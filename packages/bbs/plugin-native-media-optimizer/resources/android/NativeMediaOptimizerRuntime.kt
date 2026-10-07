package com.bbs.plugins.native_media_optimizer

import android.content.Context

internal object NativeMediaOptimizerRuntime {
    @Volatile private var instance: NativeMediaOptimizerCoordinator? = null

    fun get(context: Context): NativeMediaOptimizerCoordinator {
        requireMediaWorker()
        instance?.let { return it }
        return synchronized(this) {
            instance ?: run {
                val app = context.applicationContext
                val files = NativeMediaOptimizerFiles(app)
                NativeMediaOptimizerCoordinator(
                    NativeMediaOptimizerStore(app), files, files::resolve,
                    NativeMediaOptimizerEngines(app, files), NativeMediaOptimizerEventDispatcher::dispatch
                ).also { instance = it }
            }
        }
    }
}
