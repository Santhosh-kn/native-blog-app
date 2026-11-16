package com.bbs.plugins.native_nfc

import android.nfc.FormatException
import android.nfc.NdefRecord
import android.nfc.NdefMessage
import android.nfc.Tag
import android.nfc.tech.Ndef
import android.nfc.TagLostException
import java.io.IOException
import java.nio.ByteBuffer
import java.nio.charset.CodingErrorAction
import java.nio.charset.StandardCharsets

internal sealed interface NativeNfcTagReadResult {
    data class Success(val text: String) : NativeNfcTagReadResult
    data class Failure(val errorCode: String) : NativeNfcTagReadResult
}

internal sealed interface NativeNfcTagWriteResult {
    data object Written : NativeNfcTagWriteResult
    data class Failed(val errorCode: String) : NativeNfcTagWriteResult
    data class Unknown(val errorCode: String) : NativeNfcTagWriteResult
}

internal object NativeNfcTagHandler {

    fun readText(tag: Tag): NativeNfcTagReadResult {
        val ndef = Ndef.get(tag)
            ?: return NativeNfcTagReadResult.Failure(
                NativeNfcContract.ERROR_TAG_NOT_NDEF
            )

        try {
            ndef.connect()

            val message = ndef.ndefMessage
                ?: return NativeNfcTagReadResult.Failure(
                    NativeNfcContract.ERROR_UNSUPPORTED_TAG
                )

            val records = message.records
            if (records.size != 1) {
                return NativeNfcTagReadResult.Failure(
                    NativeNfcContract.ERROR_UNSUPPORTED_TAG
                )
            }

            val record = records[0]
            if (
                record.tnf != NdefRecord.TNF_WELL_KNOWN ||
                !record.type.contentEquals(NdefRecord.RTD_TEXT)
            ) {
                return NativeNfcTagReadResult.Failure(
                    NativeNfcContract.ERROR_UNSUPPORTED_TAG
                )
            }

            val payload = record.payload
            if (payload.size > (NativeNfcContract.MAX_TEXT_BYTES * 2) + 64) {
                return NativeNfcTagReadResult.Failure(
                    NativeNfcContract.ERROR_TEXT_TOO_LARGE
                )
            }

            if (payload.isEmpty()) {
                return NativeNfcTagReadResult.Failure(
                    NativeNfcContract.ERROR_UNSUPPORTED_TAG
                )
            }

            val status = payload[0].toInt() and 0xFF
            if ((status and 0x40) != 0) {
                return NativeNfcTagReadResult.Failure(
                    NativeNfcContract.ERROR_UNSUPPORTED_TAG
                )
            }

            val languageLength = status and 0x3F
            if (languageLength > payload.size - 1) {
                return NativeNfcTagReadResult.Failure(
                    NativeNfcContract.ERROR_UNSUPPORTED_TAG
                )
            }

            val charset = if ((status and 0x80) != 0) {
                StandardCharsets.UTF_16
            } else {
                StandardCharsets.UTF_8
            }

            val decoder = charset.newDecoder()
                .onMalformedInput(CodingErrorAction.REPORT)
                .onUnmappableCharacter(CodingErrorAction.REPORT)

            val textBytes = ByteBuffer.wrap(
                payload,
                1 + languageLength,
                payload.size - 1 - languageLength
            )

            val text = decoder.decode(textBytes).toString()

            if (text.toByteArray(StandardCharsets.UTF_8).size > NativeNfcContract.MAX_TEXT_BYTES) {
                return NativeNfcTagReadResult.Failure(
                    NativeNfcContract.ERROR_TEXT_TOO_LARGE
                )
            }

            return NativeNfcTagReadResult.Success(text)
        } catch (_: TagLostException) {
            return NativeNfcTagReadResult.Failure(
                NativeNfcContract.ERROR_TAG_LOST
            )
        } catch (_: FormatException) {
            return NativeNfcTagReadResult.Failure(
                NativeNfcContract.ERROR_UNSUPPORTED_TAG
            )
        } catch (_: IOException) {
            return NativeNfcTagReadResult.Failure(
                NativeNfcContract.ERROR_IO
            )
        } catch (_: Exception) {
            return NativeNfcTagReadResult.Failure(
                NativeNfcContract.ERROR_UNKNOWN
            )
        } finally {
            try {
                if (ndef.isConnected) ndef.close()
            } catch (_: Exception) {
                // Preserve the original read result.
            }
        }
    }
    fun writeText(tag: Tag, text: String): NativeNfcTagWriteResult {
        if (text.isBlank()) {
            return NativeNfcTagWriteResult.Failed(
                NativeNfcContract.ERROR_EMPTY_TEXT
            )
        }

        if (text.toByteArray(StandardCharsets.UTF_8).size > NativeNfcContract.MAX_TEXT_BYTES) {
            return NativeNfcTagWriteResult.Failed(
                NativeNfcContract.ERROR_TEXT_TOO_LARGE
            )
        }

        val ndef = Ndef.get(tag)
            ?: return NativeNfcTagWriteResult.Failed(
                NativeNfcContract.ERROR_TAG_NOT_NDEF
            )

        var writeAttempted = false

        try {
            ndef.connect()

            if (!ndef.isWritable) {
                return NativeNfcTagWriteResult.Failed(
                    NativeNfcContract.ERROR_TAG_READ_ONLY
                )
            }

            val record = NdefRecord.createTextRecord("en", text)
            val message = NdefMessage(arrayOf(record))
            val messageBytes = message.toByteArray()

            if (messageBytes.size > ndef.maxSize) {
                return NativeNfcTagWriteResult.Failed(
                    NativeNfcContract.ERROR_TAG_TOO_SMALL
                )
            }

            writeAttempted = true
            ndef.writeNdefMessage(message)

            return NativeNfcTagWriteResult.Written
        } catch (_: TagLostException) {
            return if (writeAttempted) {
                NativeNfcTagWriteResult.Unknown(
                    NativeNfcContract.ERROR_UNKNOWN
                )
            } else {
                NativeNfcTagWriteResult.Failed(
                    NativeNfcContract.ERROR_TAG_LOST
                )
            }
        } catch (_: FormatException) {
            return if (writeAttempted) {
                NativeNfcTagWriteResult.Unknown(
                    NativeNfcContract.ERROR_UNKNOWN
                )
            } else {
                NativeNfcTagWriteResult.Failed(
                    NativeNfcContract.ERROR_UNSUPPORTED_TAG
                )
            }
        } catch (_: IOException) {
            return if (writeAttempted) {
                NativeNfcTagWriteResult.Unknown(
                    NativeNfcContract.ERROR_UNKNOWN
                )
            } else {
                NativeNfcTagWriteResult.Failed(
                    NativeNfcContract.ERROR_IO
                )
            }
        } catch (_: Exception) {
            return if (writeAttempted) {
                NativeNfcTagWriteResult.Unknown(
                    NativeNfcContract.ERROR_UNKNOWN
                )
            } else {
                NativeNfcTagWriteResult.Failed(
                    NativeNfcContract.ERROR_UNKNOWN
                )
            }
        } finally {
            try {
                if (ndef.isConnected) ndef.close()
            } catch (_: Exception) {
                // Preserve the original write result.
            }
        }
    }

}
