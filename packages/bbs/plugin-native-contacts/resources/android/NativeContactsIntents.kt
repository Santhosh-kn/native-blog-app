package com.bbs.plugins.native_contacts

import android.content.Context
import android.content.Intent
import android.os.Build
import android.provider.ContactsContract
import com.bbs.plugins.native_contacts.NativeContactsContract as Contract

internal object NativeContactsIntents {

    fun forRequest(request: NativeContactsRequest): Intent =
        when (request.operation) {
            Contract.PICK -> pick(requireNotNull(request.mode))

            Contract.CREATE -> create().apply {
                request.name?.let {
                    putExtra(ContactsContract.Intents.Insert.NAME, it)
                }
                request.phone?.let {
                    putExtra(ContactsContract.Intents.Insert.PHONE, it)
                }
                request.email?.let {
                    putExtra(ContactsContract.Intents.Insert.EMAIL, it)
                }
            }

            Contract.OPEN -> {
                val uri = requireNotNull(Contract.contactUri(request.uri))

                Intent(Intent.ACTION_VIEW).apply {
                    setDataAndType(
                        uri,
                        ContactsContract.Contacts.CONTENT_ITEM_TYPE
                    )
                }
            }

            else -> throw IllegalArgumentException(
                "Unsupported native contacts operation."
            )
        }

    fun pick(mode: String): Intent {
        val mimeType = when (mode) {
            Contract.MODE_CONTACT ->
                ContactsContract.Contacts.CONTENT_TYPE
            Contract.MODE_PHONE ->
                ContactsContract.CommonDataKinds.Phone.CONTENT_TYPE
            Contract.MODE_EMAIL ->
                ContactsContract.CommonDataKinds.Email.CONTENT_TYPE
            else -> throw IllegalArgumentException(
                "Unsupported contact selection mode."
            )
        }

        return Intent(Intent.ACTION_PICK).apply {
            type = mimeType
        }
    }

    fun create(): Intent =
        Intent(Intent.ACTION_INSERT).apply {
            type = ContactsContract.Contacts.CONTENT_TYPE
        }

    /**
     * Explicit MIME type prevents capability checks from asking the contacts
     * provider to resolve a real contact's type. This intent is never launched.
     */
    private fun viewerProbe(): Intent =
        Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(
                requireNotNull(
                    Contract.contactUri(
                        "content://com.android.contacts/contacts/1"
                    )
                ),
                ContactsContract.Contacts.CONTENT_ITEM_TYPE
            )
        }

    fun availability(context: Context): Map<String, Any> {
        val supported = Build.VERSION.SDK_INT >= Contract.MIN_API_LEVEL

        val capabilities = linkedMapOf(
            "pickContact" to (
                supported && resolves(context, pick(Contract.MODE_CONTACT))
            ),
            "pickPhone" to (
                supported && resolves(context, pick(Contract.MODE_PHONE))
            ),
            "pickEmail" to (
                supported && resolves(context, pick(Contract.MODE_EMAIL))
            ),
            "create" to (
                supported && resolves(context, create())
            ),
            "open" to (
                supported && resolves(context, viewerProbe())
            )
        )

        val available = capabilities.values.any { it }

        return buildMap {
            put("available", available)
            put("platform", "android")
            put("apiLevel", Build.VERSION.SDK_INT)
            put("minimumApiLevel", Contract.MIN_API_LEVEL)
            put("capabilities", capabilities)

            if (!available) {
                put("errorCode", Contract.OPERATION_UNAVAILABLE)
                put(
                    "errorMessage",
                    Contract.message(Contract.OPERATION_UNAVAILABLE)
                )
            }
        }
    }

    private fun resolves(context: Context, intent: Intent): Boolean =
        try {
            intent.resolveActivity(context.packageManager) != null
        } catch (_: Exception) {
            false
        }
}