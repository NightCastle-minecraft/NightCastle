package io.github.feniksgames.sitegate.auth.api;

import java.util.List;
import java.util.concurrent.CompletableFuture;

/** API сайта для плагина. Контракт описан в plugins/docs/auth-api.md. */
public interface AuthApi {

  /** Есть ли у ника живая сессия с этого IP. */
  CompletableFuture<CheckResult> check(String name, String ip);

  /** Новый одноразовый тикет входа: игрок открывает его ссылку на сайте. */
  CompletableFuture<Ticket> createTicket(String name, String ip);

  /** Состояние тикета: игрок подтвердил вход на сайте или ещё нет. */
  CompletableFuture<TicketStatus> ticketStatus(String ticket);

  /** Отыгранные сессии для статистики. Сайт принимает повторы без дублей. */
  CompletableFuture<Void> postSessions(List<PlaySession> sessions);

  /** Онлайн для шапки сайта. */
  CompletableFuture<Void> heartbeat(int online, int max);

  record CheckResult(boolean ok, boolean registered) {}

  record Ticket(String ticket, String url, int expiresInSeconds) {}

  record TicketStatus(State state) {
    public enum State { PENDING, APPROVED, DENIED, EXPIRED }
  }

  /** Время в секундах Unix, UTC. */
  record PlaySession(String name, long start, long end) {}
}
