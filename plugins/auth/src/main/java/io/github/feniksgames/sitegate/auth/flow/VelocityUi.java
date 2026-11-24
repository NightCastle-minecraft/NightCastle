package io.github.feniksgames.sitegate.auth.flow;

import com.velocitypowered.api.network.ProtocolVersion;
import com.velocitypowered.proxy.connection.client.ConnectedPlayer;
import io.github.feniksgames.sitegate.auth.Messages;
import io.github.feniksgames.sitegate.auth.net.ConfigChannel;
import io.github.feniksgames.sitegate.auth.net.Dialogs;

/** Реализация {@link AuthSession.Ui} поверх Velocity: диалоги, keepalive и kick. */
final class VelocityUi implements AuthSession.Ui {

  private final ConnectedPlayer player;
  private final ConfigChannel channel;
  private final Messages msg;
  private final ProtocolVersion version;
  private final Runnable onApproved;

  VelocityUi(ConnectedPlayer player, ConfigChannel channel, Messages msg, Runnable onApproved) {
    this.player = player;
    this.channel = channel;
    this.msg = msg;
    this.version = player.getProtocolVersion();
    this.onApproved = onApproved;
  }

  @Override
  public void showCaptcha(Captcha captcha, int attemptsLeft, boolean wrong) {
    var prompt = msg.get(wrong ? "captcha-wrong" : "captcha-prompt",
        "x", Integer.toString(captcha.x()),
        "y", Integer.toString(captcha.y()),
        "left", Integer.toString(attemptsLeft));
    channel.show(Dialogs.captcha(version, msg.get("title"), prompt, captcha.options(),
        msg.get("leave-button")));
  }

  @Override
  public void showLogin(String url, int minutes) {
    channel.show(Dialogs.login(version,
        msg.get("title"),
        msg.get("login-body"),
        msg.get("login-hint", "minutes", Integer.toString(minutes)),
        msg.get("login-button"),
        url,
        msg.get("leave-button")));
  }

  @Override
  public void keepAlive() {
    if (!channel.isClosed()) {
      channel.keepAlive();
    }
  }

  @Override
  public void approved() {
    // Сначала отмечаем игрока, потом отпускаем: иначе ServerPreConnectEvent его не пустит.
    onApproved.run();
    if (!channel.isClosed()) {
      channel.clear();
    }
    channel.detach();
  }

  @Override
  public void kick(String messageKey, String... placeholders) {
    channel.detach();
    player.disconnect(msg.get(messageKey, placeholders));
  }
}
