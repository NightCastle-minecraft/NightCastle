package io.github.feniksgames.sitegate.shop;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CompletableFuture;

/** HTTP-клиент API магазина на сайте (Bearer-токен, JSON). */
public final class SiteShopApi implements ShopApi {

  private final URI base;
  private final String token;
  private final Duration timeout;
  private final String userAgent;
  private final HttpClient http;

  public SiteShopApi(URI base, String token, Duration timeout, String version) {
    this.base = base;
    this.token = token;
    this.timeout = timeout;
    this.userAgent = "SiteGateShop/" + version;
    this.http = HttpClient.newBuilder()
        .connectTimeout(timeout)
        .followRedirects(HttpClient.Redirect.NEVER)
        .build();
  }

  @Override
  public CompletableFuture<List<Order>> pending(int limit) {
    return send(request("/api/shop/pending?limit=" + limit).GET().build()).thenApply(json -> {
      List<Order> out = new ArrayList<>();
      JsonElement arr = json.get("orders");
      if (arr != null && arr.isJsonArray()) {
        for (JsonElement el : arr.getAsJsonArray()) {
          JsonObject o = el.getAsJsonObject();
          out.add(new Order(o.get("id").getAsLong(), o.get("name").getAsString(),
              o.get("code").getAsString(), o.has("title") ? o.get("title").getAsString() : ""));
        }
      }
      return out;
    });
  }

  @Override
  public CompletableFuture<Void> delivered(List<Long> ok, Map<Long, String> failed) {
    JsonObject body = new JsonObject();
    JsonArray okArr = new JsonArray();
    ok.forEach(okArr::add);
    JsonArray failArr = new JsonArray();
    failed.forEach((id, err) -> {
      JsonObject f = new JsonObject();
      f.addProperty("id", id);
      f.addProperty("error", err);
      failArr.add(f);
    });
    body.add("delivered", okArr);
    body.add("failed", failArr);
    return send(post("/api/shop/delivered", body)).thenApply(json -> null);
  }

  @Override
  public CompletableFuture<Void> codes(Map<String, Boolean> codes) {
    JsonArray arr = new JsonArray();
    codes.forEach((code, online) -> {
      JsonObject c = new JsonObject();
      c.addProperty("code", code);
      c.addProperty("online", online);
      arr.add(c);
    });
    JsonObject body = new JsonObject();
    body.add("codes", arr);
    return send(post("/api/shop/codes", body)).thenApply(json -> null);
  }

  @Override
  public CompletableFuture<Balance> balance(String name) {
    return send(request("/api/shop/balance/" + name).GET().build()).thenApply(json ->
        new Balance(json.has("registered") && json.get("registered").getAsBoolean(),
            json.has("balance") ? json.get("balance").getAsLong() : 0));
  }

  private HttpRequest post(String path, JsonObject body) {
    return request(path)
        .header("Content-Type", "application/json")
        .POST(HttpRequest.BodyPublishers.ofString(body.toString()))
        .build();
  }

  private HttpRequest.Builder request(String path) {
    return HttpRequest.newBuilder(URI.create(base + path))
        .timeout(timeout)
        .header("Authorization", "Bearer " + token)
        .header("Accept", "application/json")
        .header("User-Agent", userAgent);
  }

  private CompletableFuture<JsonObject> send(HttpRequest req) {
    return http.sendAsync(req, HttpResponse.BodyHandlers.ofString()).thenApply(resp -> {
      int code = resp.statusCode();
      if (code < 200 || code >= 300) {
        throw new ShopApiException(code, "сайт ответил " + code + " на " + req.uri().getPath());
      }
      JsonElement el = JsonParser.parseString(resp.body().isEmpty() ? "{}" : resp.body());
      if (!el.isJsonObject()) {
        throw new ShopApiException(code, "сайт прислал не JSON-объект");
      }
      return el.getAsJsonObject();
    });
  }
}
