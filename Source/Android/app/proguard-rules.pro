# Papiersammlung ProGuard-Regeln
-keepattributes *Annotation*,SourceFile,LineNumberTable

# OSMDroid
-keep class org.osmdroid.** { *; }

# OkHttp
-dontwarn okhttp3.**
-keep class okhttp3.** { *; }

# JSON
-keep class org.json.** { *; }

# Kotlin Coroutines
-keepnames class kotlinx.coroutines.internal.MainDispatcherFactory {}
-keepnames class kotlinx.coroutines.CoroutineExceptionHandler {}
