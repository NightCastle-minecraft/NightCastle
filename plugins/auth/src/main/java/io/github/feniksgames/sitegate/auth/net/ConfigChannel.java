package io.github.feniksgames.sitegate.auth.net;

import com.velocitypowered.proxy.connection.MinecraftConnection;
import com.velocitypowered.proxy.connection.client.ConnectedPlayer;
import com.velocitypowered.proxy.network.Connections;
import com.velocitypowered.proxy.protocol.ProtocolUtils;
import com.velocitypowered.proxy.protocol.StateRegistry;
import com.velocitypowered.proxy.protocol.packet.DialogClearPacket;
import com.velocitypowered.proxy.protocol.packet.DialogShowPacket;
import com.velocitypowered.proxy.protocol.packet.KeepAlivePacket;
import com.velocitypowered.proxy.protocol.packet.ServerboundCustomClickActionPacket;
import io.netty.channel.ChannelHandlerContext;
import io.netty.channel.ChannelInboundHandlerAdapter;
import io.netty.channel.ChannelPipeline;
import java.lang.reflect.Field;
import java.util.concurrent.ThreadLocalRandom;
import java.util.function.Consumer;
import net.kyori.adventure.nbt.CompoundBinaryTag;

/**
 * Канал к игроку в фазе конфигурации, пока он ещё не подключён ни к одному серверу.
 *
 * <p>Это единственное место, которое лезет во внутренности Velocity 4.3:
 * <ul>
 *   <li>показ и закрытие диалога идут через внутренние пакеты {@link DialogShowPacket} и
 *       {@link DialogClearPacket}, ID под версию клиента подставляет сам Velocity;</li>
 *   <li>клики по кнопкам ({@link ServerboundCustomClickActionPacket}) Velocity без подключённого сервера
 *       выкидывает, поэтому наш обработчик стоит в pipeline перед {@code handler} и забирает их сам;</li>
 *   <li>keepalive шлём сами: сервера нет, и без них клиент отвалится через 30 секунд.</li>
 * </ul>
 */
public final class ConfigChannel extends ChannelInboundHandlerAdapter {

  public static final String PIPELINE_NAME = "sitegateauth-dialog";

  private static final Field DIALOG_NBT = findDialogField();

  private final MinecraftConnection connection;
  private final Consumer<String> onClick;

  private ConfigChannel(MinecraftConnection connection, Consumer<String> onClick) {
    this.connection = connection;
    this.onClick = onClick;
  }

  /** Работают ли нужные внутренности этой версии Velocity. Если нет, вход закрываем целиком. */
  public static boolean supported() {
    return DIALOG_NBT != null;
  }

  /**
   * Ставит обработчик в pipeline игрока.
   *
   * @param onClick получает id нажатой кнопки из нашего пространства имён; вызывается на event loop
   */
  public static ConfigChannel attach(ConnectedPlayer player, Consumer<String> onClick) {
    MinecraftConnection conn = player.getConnection();
    ConfigChannel handler = new ConfigChannel(conn, onClick);
    conn.eventLoop().execute(() -> {
      ChannelPipeline p = conn.getChannel().pipeline();
      if (p.get(PIPELINE_NAME) == null && p.get(Connections.HANDLER) != null) {
        p.addBefore(Connections.HANDLER, PIPELINE_NAME, handler);
      }
    });
    return handler;
  }

  /** Снимает обработчик; безопасно вызывать повторно и после отключения игрока. */
  public void detach() {
    connection.eventLoop().execute(() -> {
      ChannelPipeline p = connection.getChannel().pipeline();
      if (p.get(PIPELINE_NAME) == this) {
        p.remove(this);
      }
    });
  }

  public void show(CompoundBinaryTag dialog) {
    DialogShowPacket packet = new DialogShowPacket(StateRegistry.CONFIG);
    try {
      DIALOG_NBT.set(packet, dialog);
    } catch (IllegalAccessException e) {
      throw new IllegalStateException("DialogShowPacket.nbt недоступно", e);
    }
    connection.write(packet);
  }

  public void clear() {
    connection.write(DialogClearPacket.INSTANCE);
  }

  public void keepAlive() {
    KeepAlivePacket packet = new KeepAlivePacket();
    packet.setRandomId(ThreadLocalRandom.current().nextLong());
    connection.write(packet);
  }

  public boolean isClosed() {
    return connection.isClosed() || !connection.getChannel().isActive();
  }

  @Override
  public void channelRead(ChannelHandlerContext ctx, Object msg) throws Exception {
    if (msg instanceof ServerboundCustomClickActionPacket click) {
      String id = readId(click);
      if (id != null && id.startsWith(Dialogs.NAMESPACE + ":")) {
        click.release();
        onClick.accept(id);
        return;
      }
    }
    ctx.fireChannelRead(msg);
  }

  /** Первое поле пакета custom_click_action: Identifier кнопки. Payload нам не нужен. */
  private static String readId(ServerboundCustomClickActionPacket click) {
    try {
      return ProtocolUtils.readString(click.content().duplicate(), 256);
    } catch (RuntimeException e) {
      return null;
    }
  }

  private static Field findDialogField() {
    try {
      Field f = DialogShowPacket.class.getDeclaredField("nbt");
      f.setAccessible(true);
      return f;
    } catch (ReflectiveOperationException | RuntimeException e) {
      return null;
    }
  }
}
