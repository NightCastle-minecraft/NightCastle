package io.github.feniksgames.sitegate.shop;

/** Сайт ответил ошибкой. */
public class ShopApiException extends RuntimeException {

  private final int status;

  public ShopApiException(int status, String message) {
    super(message);
    this.status = status;
  }

  public int status() {
    return status;
  }
}
