package io.github.feniksgames.sitegate.auth.api;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParseException;
import com.google.gson.JsonParser;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.CompletableFuture;
import java.util.regex.Pattern;

/** HTTP-клиент API сайта: Bearer-токен, JSON, все вызовы асинхронные. */
public final class SiteApi implements AuthApi {

  private static final Pattern TICKET = Pattern.compile("^[1-3][A-Za-z0-9_-]{20,64}$");

  private final HttpClient http;
  private final URI base;
  private final String token;
  private final Duration timeout;
  private final String userAgent;

  public SiteApi(URI base, String token, Duration timeout, String version) {
    this.base = base;
    this.token = token;
    this.timeout = timeout;
    this.userAgent = "SiteGateAuth/" + version;
    this.http = HttpClient.newBuilder()
        .connectTimeout(timeout)
        .followRedirects(HttpClient.Redirect.NEVER)
        .build();
  }

  @Override
  public CompletableFuture<CheckResult> check(String name, String ip) {
    JsonObject body = new JsonObject();
    body.addProperty("name", name);
    body.addProperty("ip", ip);
    return send(post("/api/auth/check", body)).thenApply(json -> {
      String status = string(json, "status");
      boolean registered = json.has("registered") && json.get("registered").getAsBoolean();
      return switch (status) {
        case "ok" -> new CheckResult(true, true);
        case "need_auth" -> new CheckResult(false, registered);
        default -> throw new ApiException(0, "check: неизвестный status " + status);
      };
    });
  }

  @Override
  public CompletableFuture<Ticket> createTicket(String name, String ip) {
    JsonObject body = new JsonObject();
    body.addProperty("name", name);
    body.addProperty("ip", ip);
    return send(post("/api/auth/tickets", body)).thenApply(json -> {
      String ticket = string(json, "ticket");
      String url = string(json, "url");
      if (!TICKET.matcher(ticket).matches()) {
        throw new ApiException(0, "tickets: странный тикет");
      }
      if (!url.startsWith(base.toString() + "/")) {
        // Ссылку открывает игрок: она обязана вести на наш сайт.
        throw new ApiException(0, "tickets: ссылка ведёт не на сайт");
      }
      int expires = json.has("expiresIn") ? json.get("expiresIn").getAsInt() : 600;
      return new Ticket(ticket, url, expires);
    });
  }

  @Override
  public CompletableFuture<TicketStatus> ticketStatus(String ticket) {
    if (!TICKET.matcher(ticket).matches()) {
      return CompletableFuture.failedFuture(new IllegalArgumentException("ticket"));
    }
    HttpRequest req = request("/api/auth/tickets/" + ticket).GET().build();
    return send(req).thenApply(json -> {
      String status = string(json, "status").toUpperCase(Locale.ROOT);
      try {
        return new TicketStatus(TicketStatus.State.valueOf(status));
      } catch (IllegalArgumentException e) {
        throw new ApiException(0, "tickets: неизвестный status " + status);
      }
    });
  }

  @Override
  public CompletableFuture<Void> postSessions(List<PlaySession> sessions) {
    JsonArray list = new JsonArray();
    for (PlaySession s : sessions) {
      JsonObject o = new JsonObject();
      o.addProperty("name", s.name());
      o.addProperty("start", s.start());
      o.addProperty("end", s.end());
      list.add(o);
    }
    JsonObject body = new JsonObject();
    body.add("sessions", list);
    return send(post("/api/stats/sessions", body)).thenApply(json -> null);
  }

  @Override
  public CompletableFuture<Void> heartbeat(int online, int max) {
    JsonObject body = new JsonObject();
    body.addProperty("online", online);
    body.addProperty("max", max);
    return send(post("/api/server/heartbeat", body)).thenApply(json -> null);
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
        throw new ApiException(code, req.method() + " " + req.uri().getPath() + " -> " + code);
      }
      String text = resp.body();
      if (text == null || text.isBlank()) {
        return new JsonObject();
      }
      try {
        JsonElement el = JsonParser.parseString(text);
        if (!el.isJsonObject()) {
          throw new ApiException(0, req.uri().getPath() + ": ответ не JSON-объект");
        }
        return el.getAsJsonObject();
      } catch (JsonParseException e) {
        throw new ApiException(0, req.uri().getPath() + ": битый JSON");
      }
    });
  }

  private static String string(JsonObject json, String key) {
    JsonElement el = json.get(key);
    if (el == null || !el.isJsonPrimitive()) {
      throw new ApiException(0, "в ответе нет поля " + key);
    }
    return el.getAsString();
  }
}
