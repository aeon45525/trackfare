package com.trackfare.phone

import android.nfc.cardemulation.HostApduService
import android.os.Bundle
import java.nio.ByteBuffer
import java.nio.ByteOrder
import java.security.KeyStore
import java.security.Signature

class TrackFareHostApduService : HostApduService() {
    private var signatureTail = byteArrayOf()

    override fun processCommandApdu(commandApdu: ByteArray, extras: Bundle?): ByteArray? {
        if (isAidSelection(commandApdu)) {
            signatureTail = byteArrayOf()
            return SUCCESS
        }

        if (commandApdu.size == 26
            && commandApdu[0] == 0x80.toByte()
            && commandApdu[1] == 0xCA.toByte()
            && commandApdu[2] == 0x00.toByte()
            && commandApdu[3] == 0x00.toByte()
            && commandApdu[4] == 0x14.toByte()
        ) {
            return createProof(commandApdu.copyOfRange(5, 25))
        }

        if (commandApdu.size == 5
            && commandApdu[0] == 0x80.toByte()
            && commandApdu[1] == 0xCA.toByte()
            && commandApdu[2] == 0x01.toByte()
        ) {
            if (signatureTail.isEmpty()) return CONDITIONS_NOT_SATISFIED
            val remaining = signatureTail
            signatureTail = byteArrayOf()
            return remaining + SUCCESS
        }

        return INSTRUCTION_NOT_SUPPORTED
    }

    @Synchronized
    override fun onDeactivated(reason: Int) {
        signatureTail = byteArrayOf()
    }

    @Synchronized
    private fun createProof(data: ByteArray): ByteArray {
        val preferences = PhoneNfcStore.preferences(this)
        val credentialId = preferences.getString(PhoneNfcStore.CREDENTIAL_ID, null)
            ?.takeIf { it.matches(Regex("[a-fA-F0-9]{32}")) }
            ?.let { hexToBytes(it) }
            ?: return CONDITIONS_NOT_SATISFIED
        val keyAlias = preferences.getString(PhoneNfcStore.KEY_ALIAS, null)
            ?: return CONDITIONS_NOT_SATISFIED

        return try {
            val tripId = ByteBuffer.wrap(data, 0, 4).order(ByteOrder.BIG_ENDIAN).int
            if (tripId < 1) return CONDITIONS_NOT_SATISFIED
            val message = "TrackFareTapV1".toByteArray(Charsets.US_ASCII) + data.copyOfRange(0, 20)
            val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
            val privateKey = keyStore.getKey(keyAlias, null) ?: return CONDITIONS_NOT_SATISFIED
            val signer = Signature.getInstance("SHA256withECDSA")
            signer.initSign(privateKey as java.security.PrivateKey)
            signer.update(message)
            val signature = signer.sign()
            if (signature.size <= 40) return CONDITIONS_NOT_SATISFIED

            signatureTail = signature.copyOfRange(36, signature.size)
            credentialId + signature.copyOfRange(0, 36) + SUCCESS
        } catch (_: Exception) {
            signatureTail = byteArrayOf()
            CONDITIONS_NOT_SATISFIED
        }
    }

    private fun isAidSelection(command: ByteArray): Boolean {
        val aid = byteArrayOf(0xF0.toByte(), 0x54, 0x52, 0x41, 0x43, 0x4B, 0x46, 0x41, 0x52, 0x45)
        return command.size >= 5 + aid.size
            && command[0] == 0x00.toByte()
            && command[1] == 0xA4.toByte()
            && command[2] == 0x04.toByte()
            && command[3] == 0x00.toByte()
            && (command[4].toInt() and 0xFF) == aid.size
            && command.copyOfRange(5, 5 + aid.size).contentEquals(aid)
    }

    private fun hexToBytes(value: String): ByteArray = ByteArray(value.length / 2) { index ->
        value.substring(index * 2, index * 2 + 2).toInt(16).toByte()
    }

    companion object {
        private val SUCCESS = byteArrayOf(0x90.toByte(), 0x00)
        private val CONDITIONS_NOT_SATISFIED = byteArrayOf(0x69, 0x85.toByte())
        private val INSTRUCTION_NOT_SUPPORTED = byteArrayOf(0x6D, 0x00)
    }
}