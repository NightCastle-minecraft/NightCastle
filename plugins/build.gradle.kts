import java.net.URI
import java.security.MessageDigest

// Velocity 4.3.1 build 45 (stable). Плагин SiteGateAuth собирается против полного jar прокси:
// диалоги в фазе конфигурации есть только во внутренних классах Velocity, в публичном API их нет.
val velocityJarName = "velocity-4.3.1-45.jar"
val velocityJarUrl =
    "https://fill-data.papermc.io/v1/objects/0844bcabdcdb4f5b875906fd4c3365bc1bb76918e42bc595ed88ad195258dd75/velocity-4.3.1-45.jar"
val velocityJarSha256 = "0844bcabdcdb4f5b875906fd4c3365bc1bb76918e42bc595ed88ad195258dd75"
val velocityJar = layout.projectDirectory.file("libs/$velocityJarName")

extra["velocityJar"] = velocityJar

fun sha256(file: File): String =
    MessageDigest.getInstance("SHA-256").digest(file.readBytes()).joinToString("") { "%02x".format(it) }

val downloadVelocity by tasks.registering {
    description = "Скачивает jar Velocity в libs/ и сверяет sha256"
    outputs.file(velocityJar)
    doLast {
        val file = velocityJar.asFile
        if (!file.exists() || sha256(file) != velocityJarSha256) {
            file.parentFile.mkdirs()
            val conn = URI(velocityJarUrl).toURL().openConnection()
            conn.setRequestProperty("User-Agent", "sitegate-build")
            conn.getInputStream().use { input -> file.outputStream().use { input.copyTo(it) } }
        }
        val actual = sha256(file)
        if (actual != velocityJarSha256) {
            file.delete()
            throw GradleException("sha256 у $velocityJarName не совпал: $actual")
        }
    }
}

subprojects {
    apply(plugin = "java")

    group = "io.github.feniksgames.sitegate"
    version = "1.0.0"

    repositories {
        mavenCentral()
    }

    extensions.configure<JavaPluginExtension> {
        toolchain.languageVersion.set(JavaLanguageVersion.of(25))
    }

    tasks.withType<JavaCompile>().configureEach {
        options.encoding = "UTF-8"
        options.release.set(25)
        options.compilerArgs.addAll(listOf("-Xlint:all", "-Xlint:-processing", "-Xlint:-serial", "-Xlint:-classfile"))
        dependsOn(rootProject.tasks.named("downloadVelocity"))
    }

    tasks.withType<Test>().configureEach {
        useJUnitPlatform()
        dependsOn(rootProject.tasks.named("downloadVelocity"))
    }
}
