package com.trackfare.phone

import java.net.URI

internal object TrackFarePaymentQr {
    private val queryPattern = Regex("v=1&r=([a-f0-9]{32})")
    private val busTapPattern = Regex("v=1&b=([1-9][0-9]{0,9})&t=([1-9][0-9]{0,9})")

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

    fun parseBusTap(payload: String): Pair<Int, Int>? {
        if (payload.length > 128) return null

        val uri = runCatching { URI(payload) }.getOrNull() ?: return null
        if (uri.scheme != "trackfare"
            || uri.rawAuthority != "tap"
            || uri.rawPath.orEmpty().isNotEmpty()
            || uri.rawFragment != null
        ) {
            return null
        }

        val match = busTapPattern.matchEntire(uri.rawQuery.orEmpty()) ?: return null
        val busId = match.groupValues[1].toIntOrNull() ?: return null
        val tripId = match.groupValues[2].toIntOrNull() ?: return null
        return busId to tripId
    }
}