package io.github.feniksgames.sitegate.auth.api;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertInstanceOf;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;
import io.github.feniksgames.sitegate.auth.api.AuthApi.TicketStatus.State;
import java.io.IOException;
import java.net.InetSocketAddress;
import java.net.URI;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.TimeUnit;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

/** HTTP-клиент против маленького сервера на JDK: формат запросов и разбор ответов по контракту. */
class SiteApiTest {

  private HttpServer server;
  private URI base;
  private final Map<String, String> lastBody = new ConcurrentHashMap<>();
  private final Map<String, String> lastAuth = new ConcurrentHashMap<>();
  private volatile String ticketUrlHost;

  @BeforeEach
  void start() throws IOException {
    server = HttpServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
    base = URI.create("http://127.0.0.1:" + server.getAddress().getPort());
    ticketUrlHost = base.toString();
    server.createContext("/api/auth/check", ex -> {
      JsonObject in = read(ex);
      reply(ex, 200, in.get("name").getAsString().equals("Steve")
          ? "{\"status\":\"ok\",\"registered\":true}"
          : "{\"status\":\"need_auth\",\"registered\":false}");
    });
    server.createContext("/api/auth/tickets", ex -> {
      if (ex.getRequestMethod().equals("POST")) {
        read(ex);
        reply(ex, 200, "{\"ticket\":\"2AbCdEfGhIjKlMnOpQrStUvWxYz_-0123456789abcd\","
            + "\"url\":\"" + ticketUrlHost + "/auth/2AbCdEfGhIjKlMnOpQrStUvWxYz_-0123456789abcd\","
            + "\"expiresIn\":600}");
      } else {
        lastAuth.put("get", ex.getRequestHeaders().getFirst("Authorization"));
        String path = ex.getRequestURI().getPath();
        reply(ex, 200, path.endsWith("abcd") ? "{\"status\":\"approved\"}" : "{\"status\":\"pending\"}");
      }
    });
    server.createContext("/api/stats/sessions", ex -> {
      read(ex);
      reply(ex, 200, "{}");
    });
    server.createContext("/api/server/heartbeat", ex -> reply(ex, 401, "{\"error\":\"unauthorized\"}"));
    server.start();
  }

  @AfterEach
  void stop() {
    server.stop(0);
  }

  private SiteApi api() {
    return new SiteApi(base, "secret-token", Duration.ofSeconds(2), "test");
  }

  @Test
  void checkSendsBearerAndParses() throws Exception {
    AuthApi.CheckResult ok = api().check("Steve", "1.2.3.4").get(3, TimeUnit.SECONDS);
    assertTrue(ok.ok());
    assertEquals("Bearer secret-token", lastAuth.get("/api/auth/check"));
    JsonObject sent = JsonParser.parseString(lastBody.get("/api/auth/check")).getAsJsonObject();
    assertEquals("1.2.3.4", sent.get("ip").getAsString());

    AuthApi.CheckResult need = api().check("Alex", "1.2.3.4").get(3, TimeUnit.SECONDS);
    assertFalse(need.ok());
    assertFalse(need.registered());
  }

  @Test
  void ticketCreateAndStatus() throws Exception {
    AuthApi.Ticket t = api().createTicket("Alex", "1.2.3.4").get(3, TimeUnit.SECONDS);
    assertEquals(600, t.expiresInSeconds());
    assertTrue(t.url().endsWith(t.ticket()));
    assertEquals(State.APPROVED, api().ticketStatus(t.ticket()).get(3, TimeUnit.SECONDS).state());
    assertEquals("Bearer secret-token", lastAuth.get("get"));
  }

  @Test
  void ticketUrlMustPointToOurSite() {
    ticketUrlHost = "https://evil.example";
    ExecutionException e = assertThrows(ExecutionException.class,
        () -> api().createTicket("Alex", "1.2.3.4").get(3, TimeUnit.SECONDS));
    assertInstanceOf(ApiException.class, e.getCause());
  }

  @Test
  void badTicketIsRejectedLocally() {
    assertThrows(ExecutionException.class,
        () -> api().ticketStatus("../../admin").get(3, TimeUnit.SECONDS));
  }

  @Test
  void sessionsBodyFormat() throws Exception {
    api().postSessions(List.of(new AuthApi.PlaySession("Steve", 100, 160))).get(3, TimeUnit.SECONDS);
    JsonObject sent = JsonParser.parseString(lastBody.get("/api/stats/sessions")).getAsJsonObject();
    JsonObject first = sent.getAsJsonArray("sessions").get(0).getAsJsonObject();
    assertEquals("Steve", first.get("name").getAsString());
    assertEquals(100, first.get("start").getAsLong());
    assertEquals(160, first.get("end").getAsLong());
  }

  @Test
  void non2xxIsApiExceptionWithStatus() {
    ExecutionException e = assertThrows(ExecutionException.class,
        () -> api().heartbeat(1, 20).get(3, TimeUnit.SECONDS));
    ApiException ae = assertInstanceOf(ApiException.class, e.getCause());
    assertEquals(401, ae.status());
  }

  private JsonObject read(HttpExchange ex) throws IOException {
    String body = new String(ex.getRequestBody().readAllBytes(), StandardCharsets.UTF_8);
    String path = ex.getRequestURI().getPath();
    lastBody.put(path, body);
    lastAuth.put(path, String.valueOf(ex.getRequestHeaders().getFirst("Authorization")));
    return JsonParser.parseString(body).getAsJsonObject();
  }

  private static void reply(HttpExchange ex, int code, String json) throws IOException {
    byte[] b = json.getBytes(StandardCharsets.UTF_8);
    ex.getResponseHeaders().add("Content-Type", "application/json");
    ex.sendResponseHeaders(code, b.length);
    ex.getResponseBody().write(b);
    ex.close();
  }
}
