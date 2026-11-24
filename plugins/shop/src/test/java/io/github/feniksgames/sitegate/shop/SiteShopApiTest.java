package io.github.feniksgames.sitegate.shop;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertInstanceOf;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import com.google.gson.JsonParser;
import com.sun.net.httpserver.HttpServer;
import java.net.InetSocketAddress;
import java.net.URI;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.TimeUnit;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

class SiteShopApiTest {

  private HttpServer http;
  private SiteShopApi api;
  private final List<String> seen = new ArrayList<>();

  @BeforeEach
  void setUp() throws Exception {
    http = HttpServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
    http.createContext("/", ex -> {
      String auth = ex.getRequestHeaders().getFirst("Authorization");
      String body = new String(ex.getRequestBody().readAllBytes(), StandardCharsets.UTF_8);
      seen.add(ex.getRequestMethod() + " " + ex.getRequestURI() + " " + body);
      String path = ex.getRequestURI().getPath();
      int code = 200;
      String out;
      if (!"Bearer t0ken".equals(auth)) {
        code = 401;
        out = "{\"error\":\"unauthorized\"}";
      } else if (path.equals("/api/shop/pending")) {
        out = "{\"orders\":[{\"id\":5,\"name\":\"Steve\",\"code\":\"vip\",\"title\":\"VIP\"}]}";
      } else if (path.equals("/api/shop/balance/Steve")) {
        out = "{\"registered\":true,\"balance\":120}";
      } else {
        out = "{\"updated\":1}";
      }
      byte[] b = out.getBytes(StandardCharsets.UTF_8);
      ex.getResponseHeaders().add("Content-Type", "application/json");
      ex.sendResponseHeaders(code, b.length);
      ex.getResponseBody().write(b);
      ex.close();
    });
    http.start();
    api = new SiteShopApi(URI.create("http://127.0.0.1:" + http.getAddress().getPort()), "t0ken", Duration.ofSeconds(3), "test");
  }

  @AfterEach
  void tearDown() {
    http.stop(0);
  }

  @Test
  void pendingAndBalance() throws Exception {
    assertEquals(List.of(new Order(5, "Steve", "vip", "VIP")), api.pending(50).get(3, TimeUnit.SECONDS));
    assertTrue(seen.get(0).startsWith("GET /api/shop/pending?limit=50"));
    assertEquals(new ShopApi.Balance(true, 120), api.balance("Steve").get(3, TimeUnit.SECONDS));
  }

  @Test
  void deliveredAndCodesBodies() throws Exception {
    Map<Long, String> failed = new LinkedHashMap<>();
    failed.put(6L, "команда упала");
    api.delivered(List.of(5L), failed).get(3, TimeUnit.SECONDS);
    var json = JsonParser.parseString(seen.get(0).substring(seen.get(0).indexOf('{'))).getAsJsonObject();
    assertEquals(5, json.getAsJsonArray("delivered").get(0).getAsLong());
    assertEquals("команда упала", json.getAsJsonArray("failed").get(0).getAsJsonObject().get("error").getAsString());
    api.codes(Map.of("vip", false)).get(3, TimeUnit.SECONDS);
    assertTrue(seen.get(1).contains("\"code\":\"vip\"") && seen.get(1).contains("\"online\":false"), seen.get(1));
  }

  @Test
  void wrongTokenIsAnError() {
    SiteShopApi bad = new SiteShopApi(URI.create("http://127.0.0.1:" + http.getAddress().getPort()), "nope", Duration.ofSeconds(3), "test");
    ExecutionException e = assertThrows(ExecutionException.class, () -> bad.pending(1).get(3, TimeUnit.SECONDS));
    assertInstanceOf(ShopApiException.class, e.getCause());
    assertEquals(401, ((ShopApiException) e.getCause()).status());
  }
}
