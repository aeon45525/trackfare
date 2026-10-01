package com.trackfare.phone

import java.net.URI

internal object TrackFarePaymentQr {
    private val queryPattern = Regex("v=1&r=([a-f0-9]{32})")

    fun parseRequestId(payload: String): String? {
        if (payload.length > 128) return null

        val uri = runCatching { URI(payload) }.getOrNull() ?: return null
        if (uri.scheme != "trackfare"
            || uri.rawAuthority != "pay"
            || uri.rawPath.orEmpty().isNotEmpty()
            || uri.rawFragment != null
        ) {
            return null
        }

        return queryPattern.matchEntire(uri.rawQuery.orEmpty())?.groupValues?.get(1)
    }
}