package io.github.feniksgames.sitegate.auth.api;

/** Сайт ответил не 2xx или вернул то, что не разобрать. */
public final class ApiException extends RuntimeException {

  private final int status;

  public ApiException(int status, String message) {
    super(message);
    this.status = status;
  }

  /** HTTP-статус; 0, если ответ пришёл, но не разобрался. */
  public int status() {
    return status;
  }
}
