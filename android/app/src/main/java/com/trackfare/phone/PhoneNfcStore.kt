package com.trackfare.phone

import android.content.Context

internal object PhoneNfcStore {
    const val PREFS = "trackfare_phone_nfc"
    const val CREDENTIAL_ID = "credential_id"
    const val KEY_ALIAS = "key_alias"
    const val PENDING_ALIAS = "pending_key_alias"

    fun preferences(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
}