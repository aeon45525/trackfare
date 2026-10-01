plugins {
	id("com.android.application")
	id("org.jetbrains.kotlin.android")
}

android {
	namespace = "com.trackfare.phone"
	compileSdk = 35

	compileOptions {
		sourceCompatibility = JavaVersion.VERSION_17
		targetCompatibility = JavaVersion.VERSION_17
	}

	defaultConfig {
		applicationId = "com.trackfare.phone"
		minSdk = 23
		targetSdk = 35
		versionCode = 11
		versionName = "1.10"
	}

	kotlinOptions {
		jvmTarget = "17"
	}
}

dependencies {
	testImplementation("junit:junit:4.13.2")
	implementation("androidx.activity:activity-ktx:1.9.3")
	implementation("androidx.camera:camera-camera2:1.4.2")
	implementation("androidx.camera:camera-lifecycle:1.4.2")
	implementation("androidx.camera:camera-view:1.4.2")
	implementation("com.google.mlkit:barcode-scanning:17.3.0")
}
