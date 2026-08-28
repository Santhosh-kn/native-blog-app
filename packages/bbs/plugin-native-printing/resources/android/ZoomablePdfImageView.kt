package com.bbs.plugins.native_printing

import android.content.Context
import android.util.AttributeSet
import android.view.MotionEvent
import android.view.ScaleGestureDetector
import androidx.appcompat.widget.AppCompatImageView

internal class ZoomablePdfImageView @JvmOverloads constructor(
    context: Context,
    attributes: AttributeSet? = null
) : AppCompatImageView(context, attributes) {

    private companion object {
        const val MINIMUM_SCALE = 1.0f
        const val MAXIMUM_SCALE = 4.0f
    }

    private var currentScale = MINIMUM_SCALE

    private val scaleDetector = ScaleGestureDetector(
        context,
        object :
            ScaleGestureDetector.SimpleOnScaleGestureListener() {

            override fun onScaleBegin(
                detector: ScaleGestureDetector
            ): Boolean {
                parent?.requestDisallowInterceptTouchEvent(
                    true
                )

                return true
            }

            override fun onScale(
                detector: ScaleGestureDetector
            ): Boolean {
                val nextScale = (
                    currentScale * detector.scaleFactor
                ).coerceIn(
                    MINIMUM_SCALE,
                    MAXIMUM_SCALE
                )

                pivotX = detector.focusX
                pivotY = detector.focusY

                currentScale = nextScale
                scaleX = nextScale
                scaleY = nextScale

                return true
            }

            override fun onScaleEnd(
                detector: ScaleGestureDetector
            ) {
                parent?.requestDisallowInterceptTouchEvent(
                    false
                )
            }
        }
    )

    init {
        adjustViewBounds = true
        scaleType = ScaleType.FIT_CENTER
        isClickable = true
    }

    override fun onTouchEvent(event: MotionEvent): Boolean {
        if (
            event.actionMasked ==
            MotionEvent.ACTION_POINTER_DOWN
        ) {
            parent?.requestDisallowInterceptTouchEvent(
                true
            )
        }

        val handled = scaleDetector.onTouchEvent(event)

        if (
            event.actionMasked == MotionEvent.ACTION_UP ||
            event.actionMasked == MotionEvent.ACTION_CANCEL
        ) {
            parent?.requestDisallowInterceptTouchEvent(
                false
            )

            if (event.actionMasked == MotionEvent.ACTION_UP) {
                performClick()
            }
        }

        return handled || super.onTouchEvent(event)
    }

    override fun performClick(): Boolean {
        super.performClick()
        return true
    }

    fun resetZoom() {
        currentScale = MINIMUM_SCALE
        scaleX = MINIMUM_SCALE
        scaleY = MINIMUM_SCALE
        pivotX = width / 2.0f
        pivotY = height / 2.0f
    }
}
