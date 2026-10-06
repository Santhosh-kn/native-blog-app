package com.bbs.plugins.native_contacts

import android.content.ContentProvider
import android.content.ContentResolver
import android.content.ContentValues
import android.database.Cursor
import android.database.MatrixCursor
import android.net.Uri
import android.os.CancellationSignal
import android.os.Looper
import android.provider.ContactsContract
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.bbs.plugins.native_contacts.NativeContactsContract as Contract
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NativeContactsSelectionReaderInstrumentedTest {

    @Test
    fun contactModeReadsOnlyTheSelectedUriAndBasicProjection() {
        val cursor = contactCursor()
        val provider = FixtureProvider { cursor }

        val selected = read(provider, Contract.MODE_CONTACT, CONTACT_URI)
            as NativeContactsSelectionReadResult.Selected

        assertEquals(NAME, selected.selection.displayName)
        assertEquals(CONTACT_URI.toString(), selected.selection.contactUri)
        assertNull(selected.selection.phoneNumber)
        assertNull(selected.selection.emailAddress)

        assertQuery(
            provider,
            CONTACT_URI,
            arrayOf(
                ContactsContract.Contacts._ID,
                ContactsContract.Contacts.DISPLAY_NAME_PRIMARY
            )
        )
        assertTrue(cursor.isClosed)
    }

    @Test
    fun phoneModeReadsOnlyTheSelectedDataRow() {
        val cursor = dataCursor(PHONE_MIME, PHONE)
        val provider = FixtureProvider { cursor }

        val selected = read(provider, Contract.MODE_PHONE, DATA_URI)
            as NativeContactsSelectionReadResult.Selected

        assertEquals(NAME, selected.selection.displayName)
        assertEquals(PHONE, selected.selection.phoneNumber)
        assertNull(selected.selection.contactUri)
        assertNull(selected.selection.emailAddress)

        assertQuery(provider, DATA_URI, DATA_COLUMNS)
        assertTrue(cursor.isClosed)
    }

    @Test
    fun emailModeReadsOnlyTheSelectedDataRow() {
        val cursor = dataCursor(EMAIL_MIME, EMAIL)
        val provider = FixtureProvider { cursor }

        val selected = read(provider, Contract.MODE_EMAIL, DATA_URI)
            as NativeContactsSelectionReadResult.Selected

        assertEquals(EMAIL, selected.selection.emailAddress)
        assertNull(selected.selection.phoneNumber)
        assertNull(selected.selection.contactUri)

        assertQuery(provider, DATA_URI, DATA_COLUMNS)
        assertTrue(cursor.isClosed)
    }

    @Test
    fun lookupUriIdHintDoesNotRequireTheCurrentContactIdToMatch() {
        val uri = Uri.parse(
            "content://com.android.contacts/contacts/lookup/fixture-key/777"
        )
        val cursor = contactCursor()
        val provider = FixtureProvider { cursor }

        val selected = read(provider, Contract.MODE_CONTACT, uri)
            as NativeContactsSelectionReadResult.Selected

        assertEquals(uri.toString(), selected.selection.contactUri)
        assertEquals(1, provider.calls)
        assertEquals(uri, provider.lastUri)
        assertTrue(cursor.isClosed)
    }

    @Test
    fun invalidModeAndUrisAreRejectedBeforeAnyProviderQuery() {
        val provider = FixtureProvider { throw AssertionError("Unexpected query.") }

        failed(read(provider, "unsupported", CONTACT_URI), Contract.INVALID_MODE)

        val invalidUris = listOf(
            null,
            Uri.parse("https://example.invalid/contacts/12345"),
            Uri.parse("content://example.invalid/contacts/12345"),
            Uri.parse("content://com.android.contacts/contacts"),
            Uri.parse("content://com.android.contacts/contacts/0"),
            Uri.parse("content://com.android.contacts/contacts/12345?fixture=1"),
            DATA_URI
        )

        for (uri in invalidUris) {
            failed(
                read(provider, Contract.MODE_CONTACT, uri),
                Contract.INVALID_SELECTION
            )
        }

        assertEquals(0, provider.calls)
    }

    @Test
    fun mainThreadReadsAreRejectedBeforeAnyProviderQuery() {
        val provider = FixtureProvider { throw AssertionError("Unexpected query.") }
        val reader = NativeContactsSelectionReader(ContentResolver.wrap(provider))
        var result: NativeContactsSelectionReadResult? = null

        InstrumentationRegistry.getInstrumentation().runOnMainSync {
            result = reader.read(Contract.MODE_CONTACT, CONTACT_URI)
        }

        failed(requireNotNull(result), Contract.CONTACT_READ_FAILED)
        assertEquals(0, provider.calls)
    }

    @Test
    fun emptyAndMultipleRowsAreRejectedAndClosed() {
        for (count in listOf(0, 2)) {
            val cursor = MatrixCursor(DATA_COLUMNS)
            repeat(count) {
                cursor.addRow(arrayOf<Any?>(12345L, PHONE_MIME, NAME, PHONE))
            }
            val provider = FixtureProvider { cursor }

            failed(
                read(provider, Contract.MODE_PHONE, DATA_URI),
                Contract.INVALID_SELECTION
            )

            assertEquals(1, provider.calls)
            assertTrue(cursor.isClosed)
        }
    }

    @Test
    fun rowIdsMustBePositiveIntegersMatchingTheSelectedNumericUri() {
        for (id in listOf<Any?>(null, 0L, -1L, "12345", 12346L)) {
            val cursor = dataCursor(PHONE_MIME, PHONE, id = id)
            val provider = FixtureProvider { cursor }

            failed(
                read(provider, Contract.MODE_PHONE, DATA_URI),
                Contract.INVALID_SELECTION
            )
            assertTrue(cursor.isClosed)
        }
    }

    @Test
    fun selectedDataMimeTypeMustMatchTheRequestedMode() {
        for (mime in listOf<Any?>(EMAIL_MIME, null, 123, "unknown/fixture")) {
            val cursor = dataCursor(mime, PHONE)
            val provider = FixtureProvider { cursor }

            failed(
                read(provider, Contract.MODE_PHONE, DATA_URI),
                Contract.INVALID_SELECTION
            )
            assertTrue(cursor.isClosed)
        }
    }

    @Test
    fun missingRequiredColumnsFailAndCloseTheCursor() {
        for (missing in listOf("_id", "mimetype", "data1")) {
            val columns = DATA_COLUMNS.filter { it != missing }.toTypedArray()
            val values = mapOf<String, Any?>(
                "_id" to 12345L,
                "mimetype" to PHONE_MIME,
                "display_name" to NAME,
                "data1" to PHONE
            )
            val cursor = MatrixCursor(columns)
            cursor.addRow(columns.map { values[it] }.toTypedArray())
            val provider = FixtureProvider { cursor }

            failed(
                read(provider, Contract.MODE_PHONE, DATA_URI),
                Contract.CONTACT_READ_FAILED
            )
            assertTrue(cursor.isClosed)
        }
    }

    @Test
    fun optionalDisplayNameCanBeMissingOrInvalidWithoutLeakingOtherFields() {
        val names = listOf<Any?>(null, 123, "x".repeat(Contract.MAX_NAME_CODE_POINTS * 2 + 1))

        for (name in names) {
            val cursor = dataCursor(PHONE_MIME, PHONE, name = name)
            val provider = FixtureProvider { cursor }

            val selected = read(provider, Contract.MODE_PHONE, DATA_URI)
                as NativeContactsSelectionReadResult.Selected

            assertNull(selected.selection.displayName)
            assertEquals(PHONE, selected.selection.phoneNumber)
            assertTrue(cursor.isClosed)
        }

        val columns = arrayOf("_id", "mimetype", "data1")
        val cursor = MatrixCursor(columns)
        cursor.addRow(arrayOf<Any?>(12345L, PHONE_MIME, PHONE))
        val provider = FixtureProvider { cursor }

        val selected = read(provider, Contract.MODE_PHONE, DATA_URI)
            as NativeContactsSelectionReadResult.Selected

        assertNull(selected.selection.displayName)
        assertTrue(cursor.isClosed)
    }

    @Test
    fun requiredPhoneAndEmailValuesMustHaveValidTypesAndBounds() {
        val cases = listOf(
            Triple(Contract.MODE_PHONE, PHONE_MIME, null),
            Triple(Contract.MODE_PHONE, PHONE_MIME, 123),
            Triple(Contract.MODE_PHONE, PHONE_MIME, ""),
            Triple(Contract.MODE_PHONE, PHONE_MIME, "1".repeat(Contract.MAX_PHONE_LENGTH + 1)),
            Triple(Contract.MODE_EMAIL, EMAIL_MIME, "invalid-address"),
            Triple(Contract.MODE_EMAIL, EMAIL_MIME, "x".repeat(Contract.MAX_EMAIL_LENGTH + 1))
        )

        for ((mode, mime, value) in cases) {
            val cursor = dataCursor(mime, value)
            val provider = FixtureProvider { cursor }

            failed(read(provider, mode, DATA_URI), Contract.INVALID_SELECTION)
            assertTrue(cursor.isClosed)
        }
    }

    @Test
    fun providerFailuresReturnControlledCodesWithoutPrivateExceptionMessages() {
        failed(
            read(FixtureProvider { null }, Contract.MODE_PHONE, DATA_URI),
            Contract.CONTACT_READ_FAILED
        )

        val denied = read(
            FixtureProvider { throw SecurityException(PRIVATE_ERROR) },
            Contract.MODE_PHONE,
            DATA_URI
        )
        failed(denied, Contract.CONTACT_ACCESS_DENIED)
        assertFalse(denied.toString().contains(PRIVATE_ERROR))

        val broken = read(
            FixtureProvider { throw IllegalStateException(PRIVATE_ERROR) },
            Contract.MODE_PHONE,
            DATA_URI
        )
        failed(broken, Contract.CONTACT_READ_FAILED)
        assertFalse(broken.toString().contains(PRIVATE_ERROR))
    }

    @Test
    fun cancellationBeforeQueryDoesNotAccessTheProvider() {
        val provider = FixtureProvider { throw AssertionError("Unexpected query.") }
        val cancellation = CancellationSignal().apply { cancel() }

        failed(
            read(provider, Contract.MODE_PHONE, DATA_URI, cancellation),
            Contract.CONTACT_READ_FAILED
        )
        assertEquals(0, provider.calls)
    }

    @Test
    fun cancellationAfterQueryClosesTheReturnedCursorWithoutDeliveringSelection() {
        val cancellation = CancellationSignal()
        val cursor = dataCursor(PHONE_MIME, PHONE)
        val provider = FixtureProvider {
            cancellation.cancel()
            cursor
        }

        failed(
            read(provider, Contract.MODE_PHONE, DATA_URI, cancellation),
            Contract.CONTACT_READ_FAILED
        )

        assertEquals(1, provider.calls)
        assertTrue(cursor.isClosed)
    }

    private fun read(
        provider: FixtureProvider,
        mode: String,
        uri: Uri?,
        cancellation: CancellationSignal = CancellationSignal()
    ): NativeContactsSelectionReadResult {
        val reader = NativeContactsSelectionReader(ContentResolver.wrap(provider))
        val executor = Executors.newSingleThreadExecutor()

        return try {
            executor.submit<NativeContactsSelectionReadResult> {
                reader.read(mode, uri, cancellation)
            }.get(5, TimeUnit.SECONDS)
        } finally {
            executor.shutdownNow()
        }
    }

    private fun failed(result: NativeContactsSelectionReadResult, code: String) {
        assertTrue(result is NativeContactsSelectionReadResult.Failed)
        assertEquals(
            code,
            (result as NativeContactsSelectionReadResult.Failed).errorCode
        )
    }

    private fun assertQuery(
        provider: FixtureProvider,
        uri: Uri,
        projection: Array<String>
    ) {
        assertEquals(1, provider.calls)
        assertEquals(uri, provider.lastUri)
        assertArrayEquals(projection, provider.lastProjection)
        assertNull(provider.lastSelection)
        assertNull(provider.lastSelectionArgs)
        assertNull(provider.lastSortOrder)
        assertFalse(provider.queriedOnMainThread)
    }

    private fun contactCursor(): MatrixCursor =
        MatrixCursor(arrayOf("_id", "display_name")).apply {
            addRow(arrayOf<Any?>(12345L, NAME))
        }

    private fun dataCursor(
        mime: Any?,
        value: Any?,
        id: Any? = 12345L,
        name: Any? = NAME
    ): MatrixCursor =
        MatrixCursor(DATA_COLUMNS).apply {
            addRow(arrayOf(id, mime, name, value))
        }

    /**
     * Wrapped directly by ContentResolver. Never registered with Android and
     * never delegates to the real Contacts provider.
     */
    private class FixtureProvider(
        private val answer: () -> Cursor?
    ) : ContentProvider() {

        var calls = 0
            private set
        var lastUri: Uri? = null
            private set
        var lastProjection: Array<String>? = null
            private set
        var lastSelection: String? = null
            private set
        var lastSelectionArgs: Array<String>? = null
            private set
        var lastSortOrder: String? = null
            private set
        var queriedOnMainThread = false
            private set

        override fun onCreate(): Boolean = true

        override fun query(
            uri: Uri,
            projection: Array<out String>?,
            selection: String?,
            selectionArgs: Array<out String>?,
            sortOrder: String?
        ): Cursor? {
            calls++
            lastUri = uri
            lastProjection = projection?.map { it }.orEmpty().toTypedArray()
            lastSelection = selection
            lastSelectionArgs = selectionArgs?.map { it }?.toTypedArray()
            lastSortOrder = sortOrder
            queriedOnMainThread = Looper.myLooper() == Looper.getMainLooper()
            return answer()
        }

        override fun getType(uri: Uri): String? = null

        override fun insert(uri: Uri, values: ContentValues?): Uri? =
            throw UnsupportedOperationException("Fixture is query-only.")

        override fun delete(
            uri: Uri,
            selection: String?,
            selectionArgs: Array<out String>?
        ): Int = throw UnsupportedOperationException("Fixture is query-only.")

        override fun update(
            uri: Uri,
            values: ContentValues?,
            selection: String?,
            selectionArgs: Array<out String>?
        ): Int = throw UnsupportedOperationException("Fixture is query-only.")
    }

    companion object {
        private val CONTACT_URI =
            Uri.parse("content://com.android.contacts/contacts/12345")
        private val DATA_URI =
            Uri.parse("content://com.android.contacts/data/12345")

        private val DATA_COLUMNS =
            arrayOf("_id", "mimetype", "display_name", "data1")

        private const val NAME = "Selection Reader Synthetic Fixture"
        private const val PHONE = "+1 202-555-0199"
        private const val EMAIL = "selection.reader@example.invalid"
        private const val PRIVATE_ERROR = "Private synthetic provider failure"

        private const val PHONE_MIME =
            ContactsContract.CommonDataKinds.Phone.CONTENT_ITEM_TYPE
        private const val EMAIL_MIME =
            ContactsContract.CommonDataKinds.Email.CONTENT_ITEM_TYPE
    }
}