val velocityJar = files(rootProject.extra["velocityJar"])

dependencies {
    // Всё (API, Netty, Adventure, Gson, nightconfig, slf4j) уже внутри Velocity: в jar плагина ничего не кладём.
    compileOnly(velocityJar)
    annotationProcessor(velocityJar)

    testImplementation(velocityJar)
    testImplementation(platform("org.junit:junit-bom:5.13.4"))
    testImplementation("org.junit.jupiter:junit-jupiter")
    testRuntimeOnly("org.junit.platform:junit-platform-launcher")
}

tasks.jar {
    archiveBaseName.set("SiteGateAuth")
}
