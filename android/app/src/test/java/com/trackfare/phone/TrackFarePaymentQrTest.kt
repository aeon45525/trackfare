package com.trackfare.phone

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class TrackFarePaymentQrTest {
    private val requestId = "0123456789abcdef0123456789abcdef"

    @Test
    fun parsesVersionOneTrackFareRequest() {
        assertEquals(
            requestId,
            TrackFarePaymentQr.parseRequestId("trackfare://pay?v=1&r=$requestId"),
        )
    }

    @Test
    fun rejectsOtherSchemesAndQrFormats() {
        assertNull(TrackFarePaymentQr.parseRequestId("https://example.com/pay?r=$requestId"))
        assertNull(TrackFarePaymentQr.parseRequestId("000201010212..."))
    }

    @Test
    fun rejectsMalformedOrUnsupportedTrackFarePayloads() {
        assertNull(TrackFarePaymentQr.parseRequestId("trackfare://pay?v=2&r=$requestId"))
        assertNull(TrackFarePaymentQr.parseRequestId("trackfare://pay?v=1&r=${requestId.uppercase()}"))
        assertNull(TrackFarePaymentQr.parseRequestId("trackfare://pay?v=1&r=$requestId&amount=1.00"))
        assertNull(TrackFarePaymentQr.parseRequestId("trackfare://other?v=1&r=$requestId"))
        assertNull(TrackFarePaymentQr.parseRequestId("trackfare://pay/path?v=1&r=$requestId"))
    }
}