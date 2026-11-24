// SiteGateShop собирается против API Leaf 26.3 и его библиотек (Adventure, Gson, SnakeYAML).
// Берём их из папки libraries локального стенда: Leaf сам скачивает их при первом запуске
// (run/smp, см. CLAUDE.md). В jar плагина ничего не кладём, всё это уже есть на сервере.
// Путь к папке libraries сервера Paper/Leaf 26.3 (появляется после первого запуска сервера):
// ./gradlew build -PserverLibs=/путь/к/серверу/libraries  (по умолчанию run/smp/libraries)
val serverLibs = (findProperty("serverLibs") as String?)?.let { file(it) } ?: rootProject.file("run/smp/libraries")
val leafLibs = fileTree(serverLibs) { include("**/*.jar") }

dependencies {
    compileOnly(leafLibs)

    testImplementation(leafLibs)
    testImplementation(platform("org.junit:junit-bom:5.13.4"))
    testImplementation("org.junit.jupiter:junit-jupiter")
    testRuntimeOnly("org.junit.platform:junit-platform-launcher")
}

tasks.jar {
    archiveBaseName.set("SiteGateShop")
}

tasks.processResources {
    filesMatching("plugin.yml") {
        expand("version" to project.version)
    }
}

tasks.named("compileJava") {
    doFirst {
        if (leafLibs.isEmpty) {
            throw GradleException("Нет библиотек сервера в $serverLibs: запустите Paper/Leaf 26.3 один раз и передайте -PserverLibs=/путь/к/libraries")
        }
    }
}
