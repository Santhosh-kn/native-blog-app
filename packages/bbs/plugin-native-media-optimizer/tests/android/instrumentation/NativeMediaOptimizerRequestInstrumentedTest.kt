package com.bbs.plugins.native_media_optimizer

import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NativeMediaOptimizerRequestInstrumentedTest {
    private val id = "3f1d6c28-78e0-4f1b-a48e-f7350dcb601a"
    private val source = "a72645ed-85bb-4f51-9d9e-8c71815646af"
    private fun base(): Map<String, Any?> = mapOf("id" to id, "source_document_id" to source)
    private fun valid(operation: String, options: Map<String, Any?> = emptyMap()): NativeMediaOptimizerRequest {
        val result = NativeMediaOptimizerRequest.parse(operation, base() + options)
        assertTrue(result is NativeMediaOptimizerRequestParseResult.Valid)
        return (result as NativeMediaOptimizerRequestParseResult.Valid).request
    }
    private fun invalid(operation: String, options: Map<String, Any?>, expected: String) {
        val result = NativeMediaOptimizerRequest.parse(operation, base() + options)
        assertTrue(result is NativeMediaOptimizerRequestParseResult.Invalid)
        assertEquals(expected, (result as NativeMediaOptimizerRequestParseResult.Invalid).code)
    }

    @Test fun defaultsMatchPhp() {
        val image = valid("OptimizeImage")
        assertEquals(1920, image.maxWidth); assertEquals(1920, image.maxHeight)
        assertEquals("jpeg", image.format); assertEquals(80, image.quality)
        val video = valid("OptimizeVideo")
        assertEquals(1920, video.maxWidth); assertEquals(1080, video.maxHeight)
        assertEquals(2500000, video.videoBitrate); assertEquals(128000, video.audioBitrate)
        assertFalse(video.removeAudio); assertEquals(0L, video.startMs); assertNull(video.endMs)
        val thumbnail = valid("GenerateThumbnail")
        assertEquals(512, thumbnail.maxWidth); assertEquals(512, thumbnail.maxHeight)
        assertEquals(0L, thumbnail.timestampMs)
        assertEquals("InspectMedia", valid("InspectMedia").operation)
    }

    @Test fun idsAreCanonicalV4() {
        assertEquals(id, NativeMediaOptimizerContract.requestId(id))
        for (bad in listOf(null, 1, id.uppercase(), " $id", "$id\n", "../$id", id.replace("-4f1b-", "-7f1b-"))) {
            assertNull(NativeMediaOptimizerContract.requestId(bad))
        }
        invalid("OptimizeImage", mapOf("id" to "bad"), NativeMediaOptimizerContract.INVALID_REQUEST_ID)
        invalid("OptimizeImage", mapOf("source_document_id" to "bad"), NativeMediaOptimizerContract.INVALID_SOURCE_ID)
    }

    @Test fun unknownFieldsAndPathsAreRejected() {
        for (operation in NativeMediaOptimizerContract.OPERATIONS) {
            invalid(operation, mapOf("path" to "/private/file"), NativeMediaOptimizerContract.INVALID_OPTIONS)
        }
        assertTrue(NativeMediaOptimizerRequest.parse("Unknown", base()) is NativeMediaOptimizerRequestParseResult.Invalid)
    }

    @Test fun dimensionsAreBoundedAndNeverCoerced() {
        for (operation in listOf("OptimizeImage", "OptimizeVideo", "GenerateThumbnail")) {
            for (bad in listOf(0, -1, "512", 512.0, true, emptyList<Int>())) {
                val result = NativeMediaOptimizerRequest.parse(operation, base() + mapOf("max_width" to bad))
                assertTrue(result is NativeMediaOptimizerRequestParseResult.Invalid)
            }
        }
        valid("OptimizeImage", mapOf("max_width" to 4096, "max_height" to 4096))
        invalid("OptimizeImage", mapOf("max_width" to 4097), NativeMediaOptimizerContract.INVALID_DIMENSIONS)
        invalid("GenerateThumbnail", mapOf("max_width" to 2049), NativeMediaOptimizerContract.INVALID_DIMENSIONS)
        valid("OptimizeVideo", mapOf("max_width" to 1080, "max_height" to 1920))
        invalid("OptimizeVideo", mapOf("max_width" to 1279), NativeMediaOptimizerContract.INVALID_DIMENSIONS)
        invalid("OptimizeVideo", mapOf("max_width" to 1920, "max_height" to 1920), NativeMediaOptimizerContract.INVALID_DIMENSIONS)
    }

    @Test fun imageFormatsAndPngQualityAreExplicit() {
        valid("OptimizeImage", mapOf("format" to "png"))
        assertNull(valid("OptimizeImage", mapOf("format" to "png")).quality)
        invalid("OptimizeImage", mapOf("format" to "png", "quality" to 80), NativeMediaOptimizerContract.INVALID_QUALITY)
        invalid("OptimizeImage", mapOf("format" to "jpg"), NativeMediaOptimizerContract.INVALID_FORMAT)
        invalid("OptimizeImage", mapOf("quality" to 101), NativeMediaOptimizerContract.INVALID_QUALITY)
        valid("OptimizeImage", mapOf("format" to "webp", "quality" to 1))
    }

    @Test fun videoSettingsAndTimeRangesAreValidated() {
        valid("OptimizeVideo", mapOf("start_ms" to 1000, "end_ms" to 5000, "remove_audio" to true))
        valid("OptimizeVideo", mapOf("end_ms" to 3600000))
        invalid("OptimizeVideo", mapOf("start_ms" to 5000, "end_ms" to 1000), NativeMediaOptimizerContract.INVALID_TIME_RANGE)
        invalid("OptimizeVideo", mapOf("start_ms" to 1000, "end_ms" to 1000), NativeMediaOptimizerContract.INVALID_TIME_RANGE)
        invalid("OptimizeVideo", mapOf("start_ms" to 3600000), NativeMediaOptimizerContract.INVALID_TIME_RANGE)
        invalid("OptimizeVideo", mapOf("end_ms" to 3600001), NativeMediaOptimizerContract.INVALID_TIME_RANGE)
        invalid("OptimizeVideo", mapOf("video_bitrate" to 127999), NativeMediaOptimizerContract.INVALID_BITRATE)
        invalid("OptimizeVideo", mapOf("audio_bitrate" to 320001), NativeMediaOptimizerContract.INVALID_BITRATE)
        invalid("OptimizeVideo", mapOf("remove_audio" to "true"), NativeMediaOptimizerContract.INVALID_OPTIONS)
        invalid("GenerateThumbnail", mapOf("timestamp_ms" to 3600000), NativeMediaOptimizerContract.INVALID_TIME_RANGE)
    }

    @Test fun oversizedRequestsAreRejectedBeforeProcessing() {
        invalid("OptimizeImage", mapOf("format" to "a".repeat(8192)), NativeMediaOptimizerContract.REQUEST_TOO_LARGE)
        assertEquals(NativeMediaOptimizerContract.INVALID_OPTIONS, NativeMediaOptimizerContract.sizeError(mapOf("id" to "\uD800")))
    }
}
