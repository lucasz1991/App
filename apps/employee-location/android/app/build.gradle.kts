plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}
android {
    namespace = "de.railtime.location"
    compileSdk = 35
    defaultConfig {
        applicationId = "de.railtime.location"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "1.0.0"
        manifestPlaceholders["railtimeHost"] = providers.gradleProperty("RAILTIME_APP_LINK_HOST").get()
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
    val releaseStore = System.getenv("RAILTIME_ANDROID_KEYSTORE")
    signingConfigs {
        if (!releaseStore.isNullOrBlank()) create("corporateRelease") {
            storeFile = file(releaseStore)
            storePassword = System.getenv("RAILTIME_ANDROID_STORE_PASSWORD")
            keyAlias = System.getenv("RAILTIME_ANDROID_KEY_ALIAS")
            keyPassword = System.getenv("RAILTIME_ANDROID_KEY_PASSWORD")
        }
    }
    buildTypes {
        release {
            isMinifyEnabled = false
            if (!releaseStore.isNullOrBlank()) signingConfig = signingConfigs.getByName("corporateRelease")
        }
    }
}
dependencies { testImplementation("junit:junit:4.13.2") }
