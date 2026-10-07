package com.bbs.plugins.native_media_optimizer

import org.json.JSONObject
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

internal data class NativeMediaOptimizerResult(
    val id: String?,
    val operation: String?,
    val accepted: Boolean,
    val status: String,
    val sourceDocumentId: String?,
    val progress: Int?,
    val phase: String,
    val input: NativeMediaOptimizerMetadata? = null,
    val output: NativeMediaOptimizerOutput? = null,
    val outputAvailable: Boolean = false,
    val errorCode: String? = null
) {
    init { require(valid()) { "Invalid media result state." } }
    override fun toString() = "NativeMediaOptimizerResult(redacted)"
    val isTerminal get() = status in Contract.TERMINAL_STATES || status == "not_found"

    private fun valid(): Boolean {
        if (id != null && Contract.requestId(id) != id) return false
        if (operation != null && operation !in Contract.OPERATIONS) return false
        if (sourceDocumentId != null && Contract.requestId(sourceDocumentId) != sourceDocumentId) return false
        if (accepted && (id == null || operation == null || sourceDocumentId == null || status == "not_found")) return false
        if (!accepted && (status !in setOf("failed", "not_found") || input != null || output != null || progress != null)) return false
        val phases = PHASES[status] ?: return false
        if (phase !in phases || (progress != null && progress !in 0..100)) return false
        if ((status == "pending" && progress != 0) || (status == "succeeded" && progress != 100) || (status in setOf("running", "cancelling") && progress == 100)) return false
        if (status in setOf("failed", "interrupted", "not_found")) {
            if (!Contract.knownError(errorCode)) return false
        } else if (errorCode != null) return false
        if (status == "interrupted" && errorCode != Contract.PROCESS_INTERRUPTED) return false
        if (status == "not_found" && errorCode != Contract.RESULT_NOT_FOUND) return false
        if (input != null) {
            if (input.isImage && input.size > Contract.MAX_IMAGE_INPUT_BYTES) return false
            if (operation == "OptimizeImage" && !input.isImage) return false
            if (operation in setOf("OptimizeVideo", "GenerateThumbnail") && input.isImage) return false
        }
        if (status == "succeeded" && input == null) return false
        if (output != null) {
            if (status != "succeeded" || operation == "InspectMedia" || output.id != id) return false
            val meta = output.metadata
            if (operation == "OptimizeVideo") {
                if (meta.mimeType != "video/mp4" || meta.width > Contract.MAX_VIDEO_EDGE || meta.height > Contract.MAX_VIDEO_EDGE || meta.width.toLong() * meta.height > Contract.MAX_VIDEO_OUTPUT_PIXELS) return false
            } else {
                val edge = if (operation == "GenerateThumbnail") Contract.MAX_THUMBNAIL_EDGE else Contract.MAX_IMAGE_EDGE
                if (!meta.isImage || meta.width > edge || meta.height > edge || meta.width.toLong() * meta.height > Contract.MAX_IMAGE_OUTPUT_PIXELS) return false
            }
        }
        if (status == "succeeded" && operation != "InspectMedia" && output == null) return false
        if (outputAvailable && output == null) return false
        return true
    }

    fun toBridgeMap(): Map<String, Any> = linkedMapOf(
        "id" to (id ?: JSONObject.NULL), "operation" to (operation ?: JSONObject.NULL),
        "accepted" to accepted, "status" to status, "sourceDocumentId" to (sourceDocumentId ?: JSONObject.NULL),
        "progress" to (progress ?: JSONObject.NULL), "phase" to phase,
        "input" to (input?.toMap() ?: JSONObject.NULL), "output" to (output?.toMap() ?: JSONObject.NULL),
        "outputAvailable" to outputAvailable, "errorCode" to (errorCode ?: JSONObject.NULL),
        "errorMessage" to (errorCode?.let(Contract::message) ?: JSONObject.NULL)
    )

    fun toEventJson(): JSONObject {
        require(accepted && isTerminal)
        return JSONObject(linkedMapOf(
            "id" to id, "operation" to operation, "status" to status,
            "errorCode" to (errorCode ?: JSONObject.NULL),
            "errorMessage" to (errorCode?.let(Contract::message) ?: JSONObject.NULL)
        ))
    }

    companion object {
        private val PHASES = mapOf(
            "pending" to setOf("queued"), "running" to setOf("inspecting", "decoding", "encoding", "finalizing"),
            "cancelling" to setOf("cancelling"), "succeeded" to setOf("completed"),
            "failed" to setOf("failed"), "cancelled" to setOf("cancelled"),
            "interrupted" to setOf("interrupted"), "not_found" to setOf("failed")
        )
        private val KEYS = setOf("id", "operation", "accepted", "status", "sourceDocumentId", "progress", "phase", "input", "output", "outputAvailable", "errorCode", "errorMessage")
        fun pending(request: NativeMediaOptimizerRequest) = NativeMediaOptimizerResult(request.id, request.operation, true, "pending", request.sourceDocumentId, 0, "queued")
        fun rejected(id: Any?, code: String, operation: String? = null) = NativeMediaOptimizerResult(
            Contract.requestId(id), operation?.takeIf { it in Contract.OPERATIONS }, false,
            if (code == Contract.RESULT_NOT_FOUND) "not_found" else "failed", null, null, "failed",
            errorCode = code.takeIf(Contract::knownError) ?: Contract.INVALID_NATIVE_RESPONSE
        )

        fun fromJson(value: Any?): NativeMediaOptimizerResult? {
            val json = value as? JSONObject ?: return null
            return try {
                if (json.keys().asSequence().toSet() != KEYS) return null
                fun nullableString(key: String): String? {
                    val raw = json.get(key)
                    if (raw === JSONObject.NULL) return null
                    return raw as? String ?: throw IllegalArgumentException("Invalid metadata.")
                }
                val progressValue = json.get("progress")
                val progress = if (progressValue === JSONObject.NULL) null else Contract.integer(progressValue)?.takeIf { it in 0L..100L }?.toInt() ?: return null
                val inputRaw = json.get("input")
                val outputRaw = json.get("output")
                val input = if (inputRaw === JSONObject.NULL) null else NativeMediaOptimizerMetadata.fromJson(inputRaw) ?: return null
                val output = if (outputRaw === JSONObject.NULL) null else NativeMediaOptimizerOutput.fromJson(outputRaw) ?: return null
                NativeMediaOptimizerResult(
                    nullableString("id"), nullableString("operation"), json.get("accepted") as? Boolean ?: return null,
                    json.get("status") as? String ?: return null, nullableString("sourceDocumentId"), progress,
                    json.get("phase") as? String ?: return null, input, output,
                    json.get("outputAvailable") as? Boolean ?: return null, nullableString("errorCode")
                ).takeIf { result ->
                    val message = json.get("errorMessage")
                    if (result.errorCode == null) message === JSONObject.NULL else message == Contract.message(result.errorCode)
                }
            } catch (_: Exception) { null }
        }
    }
}
