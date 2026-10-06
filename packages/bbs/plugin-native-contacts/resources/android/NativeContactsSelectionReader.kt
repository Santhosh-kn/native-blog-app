package com.bbs.plugins.native_contacts

import android.content.ContentResolver
import android.content.Context
import android.database.Cursor
import android.net.Uri
import android.os.CancellationSignal
import android.os.Looper
import android.provider.ContactsContract
import com.bbs.plugins.native_contacts.NativeContactsContract as Contract

internal sealed interface NativeContactsSelectionReadResult {

    data class Selected(
        val selection: NativeContactsSelection
    ) : NativeContactsSelectionReadResult {
        override fun toString(): String =
            "NativeContactsSelectionReadResult.Selected(redacted)"
    }

    data class Failed(
        val errorCode: String
    ) : NativeContactsSelectionReadResult
}

/**
 * Reads only the URI returned by the picker.
 *
 * Contact mode reads basic contact metadata.
 * Phone/email modes read the selected data row and verify its MIME type.
 * There are no collection queries, secondary lookups, or persisted URI grants.
 */
internal class NativeContactsSelectionReader(
    private val resolver: ContentResolver
) {

    constructor(context: Context) : this(
        context.applicationContext.contentResolver
    )

    override fun toString(): String =
        "NativeContactsSelectionReader(private)"

    fun read(
        mode: String,
        returnedUri: Uri?,
        cancellation: CancellationSignal = CancellationSignal()
    ): NativeContactsSelectionReadResult {
        if (mode !in Contract.modes) {
            return failed(Contract.INVALID_MODE)
        }

        val uri = try {
            Contract.selectedUri(returnedUri?.toString(), mode)
        } catch (_: Exception) {
            null
        } ?: return failed(Contract.INVALID_SELECTION)

        // Provider reads must run on the coordinator's worker thread.
        if (Looper.myLooper() == Looper.getMainLooper()) {
            return failed(Contract.CONTACT_READ_FAILED)
        }

        return try {
            cancellation.throwIfCanceled()

            val cursor = resolver.query(
                uri,
                projection(mode),
                null,
                null,
                null,
                cancellation
            ) ?: return failed(Contract.CONTACT_READ_FAILED)

            cursor.use { row ->
                cancellation.throwIfCanceled()

                if (!row.moveToFirst()) {
                    throw ReadFailure(Contract.INVALID_SELECTION)
                }

                verifyRowId(row, uri, mode)

                if (mode != Contract.MODE_CONTACT) {
                    verifyDataType(row, mode)
                }

                val name = text(
                    cursor = row,
                    column = ContactsContract.Contacts.DISPLAY_NAME_PRIMARY,
                    maxLength = Contract.MAX_NAME_CODE_POINTS * 2,
                    required = false
                )?.takeIf { Contract.isName(it) }

                val selected = when (mode) {
                    Contract.MODE_CONTACT -> NativeContactsSelection(
                        displayName = name,
                        contactUri = uri.toString()
                    )

                    Contract.MODE_PHONE -> NativeContactsSelection(
                        displayName = name,
                        phoneNumber = text(
                            cursor = row,
                            column = ContactsContract.CommonDataKinds.Phone.NUMBER,
                            maxLength = Contract.MAX_PHONE_LENGTH,
                            required = true
                        )
                    )

                    Contract.MODE_EMAIL -> NativeContactsSelection(
                        displayName = name,
                        emailAddress = text(
                            cursor = row,
                            column = ContactsContract.CommonDataKinds.Email.ADDRESS,
                            maxLength = Contract.MAX_EMAIL_LENGTH,
                            required = true
                        )
                    )

                    else -> throw ReadFailure(Contract.INVALID_MODE)
                }

                if (!selected.isValid(mode)) {
                    throw ReadFailure(Contract.INVALID_SELECTION)
                }

                // A single selected URI must resolve to one row.
                if (row.moveToNext()) {
                    throw ReadFailure(Contract.INVALID_SELECTION)
                }

                cancellation.throwIfCanceled()

                NativeContactsSelectionReadResult.Selected(selected)
            }
        } catch (failure: ReadFailure) {
            failed(failure.errorCode)
        } catch (_: SecurityException) {
            failed(Contract.CONTACT_ACCESS_DENIED)
        } catch (_: Exception) {
            failed(Contract.CONTACT_READ_FAILED)
        }
    }

    private fun projection(mode: String): Array<String> =
        when (mode) {
            Contract.MODE_CONTACT -> arrayOf(
                ContactsContract.Contacts._ID,
                ContactsContract.Contacts.DISPLAY_NAME_PRIMARY
            )

            Contract.MODE_PHONE -> arrayOf(
                ContactsContract.Data._ID,
                ContactsContract.Data.MIMETYPE,
                ContactsContract.Contacts.DISPLAY_NAME_PRIMARY,
                ContactsContract.CommonDataKinds.Phone.NUMBER
            )

            Contract.MODE_EMAIL -> arrayOf(
                ContactsContract.Data._ID,
                ContactsContract.Data.MIMETYPE,
                ContactsContract.Contacts.DISPLAY_NAME_PRIMARY,
                ContactsContract.CommonDataKinds.Email.ADDRESS
            )

            else -> throw ReadFailure(Contract.INVALID_MODE)
        }

    private fun verifyRowId(
        cursor: Cursor,
        uri: Uri,
        mode: String
    ) {
        val index = cursor.getColumnIndex(ContactsContract.Data._ID)

        if (index < 0) {
            throw ReadFailure(Contract.CONTACT_READ_FAILED)
        }

        if (
            cursor.isNull(index) ||
            cursor.getType(index) != Cursor.FIELD_TYPE_INTEGER
        ) {
            throw ReadFailure(Contract.INVALID_SELECTION)
        }

        val actual = cursor.getLong(index)
        if (actual <= 0L) {
            throw ReadFailure(Contract.INVALID_SELECTION)
        }

        val segments = uri.pathSegments

        val expected = if (mode == Contract.MODE_CONTACT) {
            // A lookup URI's optional ID is a hint and can change after merging.
            if (segments.size == 2 && segments[0] == "contacts") {
                segments[1].toLongOrNull()
            } else {
                null
            }
        } else {
            uri.lastPathSegment?.toLongOrNull()
                ?: throw ReadFailure(Contract.INVALID_SELECTION)
        }

        if (expected != null && actual != expected) {
            throw ReadFailure(Contract.INVALID_SELECTION)
        }
    }

    private fun verifyDataType(cursor: Cursor, mode: String) {
        val actual = text(
            cursor = cursor,
            column = ContactsContract.Data.MIMETYPE,
            maxLength = 128,
            required = true
        )

        val expected = when (mode) {
            Contract.MODE_PHONE ->
                ContactsContract.CommonDataKinds.Phone.CONTENT_ITEM_TYPE
            Contract.MODE_EMAIL ->
                ContactsContract.CommonDataKinds.Email.CONTENT_ITEM_TYPE
            else -> throw ReadFailure(Contract.INVALID_MODE)
        }

        if (actual != expected) {
            throw ReadFailure(Contract.INVALID_SELECTION)
        }
    }

    private fun text(
        cursor: Cursor,
        column: String,
        maxLength: Int,
        required: Boolean
    ): String? {
        val index = cursor.getColumnIndex(column)

        if (index < 0) {
            if (required) {
                throw ReadFailure(Contract.CONTACT_READ_FAILED)
            }
            return null
        }

        if (
            cursor.isNull(index) ||
            cursor.getType(index) != Cursor.FIELD_TYPE_STRING
        ) {
            if (required) {
                throw ReadFailure(Contract.INVALID_SELECTION)
            }
            return null
        }

        val value = cursor.getString(index)

        if (value == null || value.length > maxLength) {
            if (required) {
                throw ReadFailure(Contract.INVALID_SELECTION)
            }
            return null
        }

        return value
    }

    private fun failed(
        errorCode: String
    ): NativeContactsSelectionReadResult.Failed =
        NativeContactsSelectionReadResult.Failed(errorCode)

    private class ReadFailure(
        val errorCode: String
    ) : RuntimeException("Native contacts selection could not be read.")
}