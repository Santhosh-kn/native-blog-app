package com.bbs.plugins.native_background_transfer

import java.io.File
import java.io.FileInputStream
import java.nio.ByteBuffer
import java.nio.charset.CodingErrorAction
import java.util.zip.ZipException
import java.util.zip.ZipFile

internal class NativeBackgroundTransferContentValidator {

    fun validate(
        file: File,
        mimeType: String
    ): Boolean {
        if (
            !file.isFile ||
            !file.canRead() ||
            file.length() <= 0L
        ) {
            return false
        }

        return try {
            when (mimeType) {
                "application/pdf" ->
                    hasPrefix(
                        file,
                        byteArrayOf(
                            0x25,
                            0x50,
                            0x44,
                            0x46,
                            0x2D
                        )
                    )

                "image/jpeg" ->
                    hasPrefix(
                        file,
                        byteArrayOf(
                            0xFF.toByte(),
                            0xD8.toByte(),
                            0xFF.toByte()
                        )
                    )

                "image/png" ->
                    hasPrefix(
                        file,
                        byteArrayOf(
                            0x89.toByte(),
                            0x50,
                            0x4E,
                            0x47,
                            0x0D,
                            0x0A,
                            0x1A,
                            0x0A
                        )
                    )

                "image/webp" ->
                    isWebP(file)

                "application/zip" ->
                    hasZipSignature(file)

                "application/vnd.openxmlformats-officedocument.wordprocessingml.document" ->
                    isDocx(file)

                "application/msword" ->
                    hasPrefix(
                        file,
                        byteArrayOf(
                            0xD0.toByte(),
                            0xCF.toByte(),
                            0x11,
                            0xE0.toByte(),
                            0xA1.toByte(),
                            0xB1.toByte(),
                            0x1A,
                            0xE1.toByte()
                        )
                    )

                "text/plain",
                "text/csv" ->
                    isSafeUtf8Text(file)

                else ->
                    false
            }
        } catch (_: Exception) {
            false
        }
    }

    private fun isWebP(
        file: File
    ): Boolean {
        val header = readPrefix(
            file = file,
            maximumBytes = 12
        )

        if (header.size < 12) {
            return false
        }

        return header.copyOfRange(
            0,
            4
        ).contentEquals(
            byteArrayOf(
                'R'.code.toByte(),
                'I'.code.toByte(),
                'F'.code.toByte(),
                'F'.code.toByte()
            )
        ) &&
            header.copyOfRange(
                8,
                12
            ).contentEquals(
                byteArrayOf(
                    'W'.code.toByte(),
                    'E'.code.toByte(),
                    'B'.code.toByte(),
                    'P'.code.toByte()
                )
            )
    }

    private fun hasZipSignature(
        file: File
    ): Boolean {
        val prefix = readPrefix(
            file = file,
            maximumBytes = 4
        )

        if (prefix.size < 4) {
            return false
        }

        val signatures = listOf(
            byteArrayOf(
                0x50,
                0x4B,
                0x03,
                0x04
            ),
            byteArrayOf(
                0x50,
                0x4B,
                0x05,
                0x06
            ),
            byteArrayOf(
                0x50,
                0x4B,
                0x07,
                0x08
            )
        )

        return signatures.any {
            prefix.contentEquals(it)
        }
    }

    private fun isDocx(
        file: File
    ): Boolean {
        if (!hasZipSignature(file)) {
            return false
        }

        return try {
            ZipFile(file).use { zip ->
                val contentTypes =
                    zip.getEntry(
                        "[Content_Types].xml"
                    )

                val document =
                    zip.getEntry(
                        "word/document.xml"
                    )

                contentTypes != null &&
                    !contentTypes.isDirectory &&
                    document != null &&
                    !document.isDirectory
            }
        } catch (_: ZipException) {
            false
        }
    }

    private fun isSafeUtf8Text(
        file: File
    ): Boolean {
        val sample = readPrefix(
            file = file,
            maximumBytes = TEXT_SAMPLE_SIZE
        )

        if (sample.isEmpty()) {
            return false
        }

        if (
            sample.any {
                it == 0.toByte()
            }
        ) {
            return false
        }

        val decoded = try {
            Charsets.UTF_8
                .newDecoder()
                .onMalformedInput(
                    CodingErrorAction.REPORT
                )
                .onUnmappableCharacter(
                    CodingErrorAction.REPORT
                )
                .decode(
                    ByteBuffer.wrap(sample)
                )
                .toString()
        } catch (_: Exception) {
            return false
        }

        return decoded.none { character ->
            val code = character.code

            code < 0x20 &&
                character != '\t' &&
                character != '\r' &&
                character != '\n'
        }
    }

    private fun hasPrefix(
        file: File,
        expected: ByteArray
    ): Boolean {
        val actual = readPrefix(
            file = file,
            maximumBytes = expected.size
        )

        return actual.contentEquals(
            expected
        )
    }

    private fun readPrefix(
        file: File,
        maximumBytes: Int
    ): ByteArray {
        if (maximumBytes <= 0) {
            return byteArrayOf()
        }

        FileInputStream(file).use { input ->
            val buffer =
                ByteArray(maximumBytes)

            var offset = 0

            while (offset < maximumBytes) {
                val count = input.read(
                    buffer,
                    offset,
                    maximumBytes - offset
                )

                if (count == -1) {
                    break
                }

                if (count == 0) {
                    continue
                }

                offset += count
            }

            return buffer.copyOf(offset)
        }
    }

    private companion object {
        const val TEXT_SAMPLE_SIZE =
            8 * 1024
    }
}