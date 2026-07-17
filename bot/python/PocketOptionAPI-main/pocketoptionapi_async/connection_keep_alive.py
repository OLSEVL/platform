"""
Enhanced Keep-Alive Connection Manager for PocketOption Async API
"""

import asyncio
import json
import re
from typing import Optional, List, Callable, Dict, Any, Union
from datetime import datetime, timedelta
from loguru import logger
from websockets.exceptions import ConnectionClosed
from websockets.legacy.client import connect, WebSocketClientProtocol

from .models import ConnectionInfo, ConnectionStatus
from .constants import REGIONS

# Socket.IO EVENT: "42[...]" или с ack-id "4212[...]" / binary preview "451-[...]".
_SIO_EVENT_RE = re.compile(r"^42\d*(\[.*\])\s*$", re.DOTALL)


class ConnectionKeepAlive:
    """
    Advanced connection keep-alive manager based on old API patterns.

    proxy_url — HTTP/SOCKS прокси (как у AsyncWebSocketClient). Без него зеркало
    place-order ходит с IP VPS и провоцирует бан у Pocket Option.
    """

    def __init__(self, ssid: str, is_demo: bool = True, proxy_url: Optional[str] = None):
        self.ssid = ssid
        self.is_demo = is_demo
        self.proxy_url = proxy_url

        # Connection state
        self.websocket: Optional[WebSocketClientProtocol] = None
        self.connection_info: Optional[ConnectionInfo] = None
        self.is_connected = False
        self.should_reconnect = True

        # Background tasks
        self._ping_task: Optional[asyncio.Task] = None
        self._reconnect_task: Optional[asyncio.Task] = None
        self._message_task: Optional[asyncio.Task] = None
        self._health_task: Optional[asyncio.Task] = None

        # Keep-alive settings
        self.ping_interval = 20  # seconds (same as old API)
        self.reconnect_delay = 5  # seconds
        self.max_reconnect_attempts = 10
        self.current_reconnect_attempts = 0

        # Event handlers
        self._event_handlers: Dict[str, List[Callable]] = {}

        # Socket.IO binary EVENT: после `451-[...]` ждём N binary frames.
        self._binary_pending: Optional[Dict[str, Any]] = None

        # Connection pool with multiple regions
        self.available_urls = (
            REGIONS.get_demo_regions() if is_demo else REGIONS.get_all()
        )
        self.current_url_index = 0

        # Statistics
        self.connection_stats = {
            "total_connections": 0,
            "successful_connections": 0,
            "total_reconnects": 0,
            "last_ping_time": None,
            "last_pong_time": None,
            "total_messages_sent": 0,
            "total_messages_received": 0,
        }

        logger.info(
            f"Initialized keep-alive manager with {len(self.available_urls)} available regions"
        )

    async def start_persistent_connection(self) -> bool:
        """
        Start a persistent connection with automatic keep-alive
        Similar to old API's daemon thread approach but with modern async
        """
        logger.info("Starting persistent connection with keep-alive...")

        try:
            # Initial connection
            if await self._establish_connection():
                # Start all background tasks
                await self._start_background_tasks()
                logger.success(
                    "Success: Persistent connection established with keep-alive active"
                )
                return True
            else:
                logger.error("Error: Failed to establish initial connection")
                return False

        except Exception as e:
            logger.error(f"Error: Error starting persistent connection: {e}")
            return False

    async def stop_persistent_connection(self):
        """Stop the persistent connection and all background tasks"""
        logger.info("Stopping persistent connection...")

        self.should_reconnect = False

        # Cancel all background tasks
        tasks = [
            self._ping_task,
            self._reconnect_task,
            self._message_task,
            self._health_task,
        ]
        for task in tasks:
            if task and not task.done():
                task.cancel()
                try:
                    await task
                except asyncio.CancelledError:
                    pass

        # Close connection
        if self.websocket:
            await self.websocket.close()
            self.websocket = None

        self.is_connected = False
        logger.info("Success: Persistent connection stopped")

    async def _establish_connection(self) -> bool:
        """
        Establish connection with fallback URLs (like old API)
        """
        for attempt in range(len(self.available_urls)):
            url = self.available_urls[self.current_url_index]

            try:
                logger.info(
                    f"Connecting: Attempting connection to {url} (attempt {attempt + 1})"
                )

                # SSL context (like old API)
                import ssl

                ssl_context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
                ssl_context.check_hostname = False
                ssl_context.verify_mode = ssl.CERT_NONE

                extra_headers = {
                    "Origin": "https://pocketoption.com",
                    "Cache-Control": "no-cache",
                    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
                }
                connect_kwargs = dict(
                    ssl=ssl_context,
                    extra_headers=extra_headers,
                    ping_interval=None,  # We handle pings manually
                    ping_timeout=None,
                    close_timeout=10,
                )

                # Тот же механизм, что в AsyncWebSocketClient: без proxy_connect
                # persistent WS (зеркало) выходит напрямую с IP сервера.
                if self.proxy_url:
                    from websockets_proxy import Proxy, proxy_connect

                    proxy = Proxy.from_url(self.proxy_url)
                    host_hint = (
                        self.proxy_url.split("@")[-1]
                        if "@" in self.proxy_url
                        else "set"
                    )
                    # print в stdout: в сервисе enable_logging=False глушит loguru.
                    print(f"[INFO] KeepAlive Connecting via proxy ({host_hint})", flush=True)
                    logger.info(f"Connecting via proxy ({host_hint})")
                    self.websocket = await asyncio.wait_for(
                        proxy_connect(url, proxy=proxy, **connect_kwargs),
                        timeout=15.0,
                    )
                else:
                    self.websocket = await asyncio.wait_for(
                        connect(url, **connect_kwargs),
                        timeout=15.0,
                    )

                # Update connection info
                region = self._extract_region_from_url(url)
                self.connection_info = ConnectionInfo(
                    url=url,
                    region=region,
                    status=ConnectionStatus.CONNECTED,
                    connected_at=datetime.now(),
                    reconnect_attempts=self.current_reconnect_attempts,
                )

                self.is_connected = True
                self.current_reconnect_attempts = 0
                self.connection_stats["total_connections"] += 1
                self.connection_stats["successful_connections"] += 1

                # Send initial handshake (like old API)
                await self._send_handshake()

                logger.success(f"Success: Connected to {region} region successfully")
                await self._emit_event("connected", {"url": url, "region": region})

                return True

            except Exception as e:
                logger.warning(f"Caution: Failed to connect to {url}: {e}")

                # Try next URL
                self.current_url_index = (self.current_url_index + 1) % len(
                    self.available_urls
                )

                if self.websocket:
                    try:
                        await self.websocket.close()
                    except Exception:
                        pass
                    self.websocket = None

                await asyncio.sleep(1)  # Brief delay before next attempt

        return False

    async def _send_handshake(self):
        """Send initial handshake sequence (like old API)"""
        try:
            if not self.websocket:
                raise RuntimeError("Handshake called with no websocket connection.")
            # Wait for initial connection message
            initial_message = await asyncio.wait_for(
                self.websocket.recv(), timeout=10.0
            )
            logger.debug(f"Received initial: {initial_message}")

            # Send handshake sequence (like old API)
            await self.websocket.send("40")
            await asyncio.sleep(0.1)

            # Wait for connection establishment
            conn_message = await asyncio.wait_for(self.websocket.recv(), timeout=10.0)
            logger.debug(f"Received connection: {conn_message}")

            # Send SSID authentication
            await self.websocket.send(self.ssid)
            logger.debug("Handshake completed")

            self.connection_stats["total_messages_sent"] += 2

        except Exception as e:
            logger.error(f"Handshake failed: {e}")
            raise

    async def _start_background_tasks(self):
        """Start all background tasks (like old API's concurrent tasks)."""
        logger.info("Persistent: Starting background keep-alive tasks...")
        print("[INFO] KeepAlive starting background tasks (ping/message/health/reconnect)", flush=True)

        # Не плодим дубликаты при reconnect — отменяем старые завершившиеся/живые таски.
        for task_name in ("_ping_task", "_message_task", "_health_task", "_reconnect_task"):
            old = getattr(self, task_name, None)
            if old is not None and not old.done():
                # message/ping/health нужно пересоздать после reconnect; reconnect monitor — один.
                if task_name == "_reconnect_task":
                    continue
                old.cancel()
                try:
                    await old
                except (asyncio.CancelledError, Exception):
                    pass

        self._ping_task = asyncio.create_task(self._ping_loop())
        self._message_task = asyncio.create_task(self._message_loop())
        self._health_task = asyncio.create_task(self._health_monitor_loop())

        if self._reconnect_task is None or self._reconnect_task.done():
            self._reconnect_task = asyncio.create_task(self._reconnection_monitor())

        logger.success("Success: All background tasks started")

    def message_loop_alive(self) -> bool:
        """True, если приём WS ещё жив (после disconnect таск мог умереть)."""
        t = self._message_task
        return t is not None and not t.done()

    async def _ping_loop(self):
        """
        Continuous ping loop (like old API's send_ping function)
        Sends '42["ps"]' every 20 seconds
        """
        logger.info("Ping: Starting ping loop...")
        print("[INFO] KeepAlive ping loop started", flush=True)

        while self.should_reconnect:
            try:
                if self.is_connected and self.websocket:
                    await self.websocket.send('42["ps"]')
                    self.connection_stats["last_ping_time"] = datetime.now()
                    self.connection_stats["total_messages_sent"] += 1
                    logger.debug("Ping: Ping sent")

                await asyncio.sleep(self.ping_interval)

            except ConnectionClosed:
                logger.warning("Connecting: Connection closed during ping")
                print("[WARN] KeepAlive ping: connection closed", flush=True)
                self.is_connected = False
                await asyncio.sleep(1)
            except asyncio.CancelledError:
                raise
            except Exception as e:
                logger.error(f"Error: Ping failed: {e}")
                print(f"[WARN] KeepAlive ping error: {e}", flush=True)
                self.is_connected = False
                await asyncio.sleep(1)

    async def _message_loop(self):
        """
        Continuous message receiving loop.

        Важно: при ConnectionClosed НЕ выходим из while — иначе после reconnect
        ордера отправляются, а successopen/close никто не читает (это и ломало PO-итог).
        """
        logger.info("Message: Starting message loop...")
        print("[INFO] KeepAlive message loop started", flush=True)

        while self.should_reconnect:
            try:
                if self.is_connected and self.websocket:
                    try:
                        message = await asyncio.wait_for(
                            self.websocket.recv(), timeout=30.0
                        )

                        self.connection_stats["total_messages_received"] += 1

                        # Binary attachment после 451-N-[...] — не декодируем как текст.
                        if (
                            isinstance(message, bytes)
                            and self._binary_pending is not None
                        ):
                            await self._consume_binary_attachment(message)
                            continue

                        # Диагностика текстовых success*/Order кадров.
                        preview = (
                            message
                            if isinstance(message, str)
                            else (message[:80] if isinstance(message, bytes) else repr(message))
                        )
                        if isinstance(preview, str) and (
                            "successopen" in preview
                            or "successclose" in preview
                            or "successauth" in preview
                            or "openOrder" in preview
                            or preview.startswith("451-")
                            or preview.startswith("45")
                        ):
                            print(f"[MIRROR] WS in: {preview[:240]}", flush=True)

                        await self._process_message(message)

                    except asyncio.TimeoutError:
                        logger.debug("Message: Message receive timeout (normal)")
                        continue
                else:
                    await asyncio.sleep(0.5)

            except ConnectionClosed:
                logger.warning("Connecting: Connection closed during message receive")
                print("[WARN] KeepAlive message loop: connection closed — wait reconnect", flush=True)
                self.is_connected = False
                await asyncio.sleep(1)
            except asyncio.CancelledError:
                raise
            except Exception as e:
                logger.error(f"Error: Message loop error: {e}")
                print(f"[WARN] KeepAlive message loop error: {e}", flush=True)
                self.is_connected = False
                await asyncio.sleep(1)

    async def _health_monitor_loop(self):
        """Monitor connection health and trigger reconnects if needed"""
        logger.info("Health: Starting health monitor...")

        while self.should_reconnect:
            try:
                await asyncio.sleep(30)  # Check every 30 seconds

                if not self.is_connected:
                    logger.warning("Health: Health check: Connection lost")
                    continue

                # Check if we received a pong recently
                if self.connection_stats["last_ping_time"]:
                    time_since_ping = (
                        datetime.now() - self.connection_stats["last_ping_time"]
                    )
                    if time_since_ping > timedelta(
                        seconds=60
                    ):  # No response for 60 seconds
                        logger.warning(
                            "Health: Health check: No ping response, connection may be dead"
                        )
                        self.is_connected = False

                # Check WebSocket state
                if self.websocket and self.websocket.closed:
                    logger.warning("Health: Health check: WebSocket is closed")
                    self.is_connected = False

            except Exception as e:
                logger.error(f"Error: Health monitor error: {e}")

    async def _reconnection_monitor(self):
        """
        Monitor for disconnections and automatically reconnect (like old API)
        """
        logger.info("Persistent: Starting reconnection monitor...")

        while self.should_reconnect:
            try:
                await asyncio.sleep(5)  # Check every 5 seconds

                if not self.is_connected and self.should_reconnect:
                    logger.warning(
                        "Persistent: Detected disconnection, attempting reconnect..."
                    )

                    self.current_reconnect_attempts += 1
                    self.connection_stats["total_reconnects"] += 1

                    if self.current_reconnect_attempts <= self.max_reconnect_attempts:
                        logger.info(
                            f"Persistent: Reconnection attempt {self.current_reconnect_attempts}/{self.max_reconnect_attempts}"
                        )

                        # Clean up current connection
                        if self.websocket:
                            try:
                                await self.websocket.close()
                            except Exception:
                                pass
                            self.websocket = None

                        # Try to reconnect
                        success = await self._establish_connection()

                        if success:
                            logger.success("Success: Reconnection successful!")
                            print("[INFO] KeepAlive reconnected — restarting recv/ping tasks", flush=True)
                            # После disconnect старый message/ping loop мог завершиться —
                            # поднимаем приём заново, иначе openOrder send есть, а close нет.
                            await self._start_background_tasks()
                            await self._emit_event(
                                "reconnected",
                                {
                                    "attempt": self.current_reconnect_attempts,
                                    "url": self.connection_info.url
                                    if self.connection_info
                                    else None,
                                },
                            )
                        else:
                            logger.error(
                                f"Error: Reconnection attempt {self.current_reconnect_attempts} failed"
                            )
                            await asyncio.sleep(self.reconnect_delay)
                    else:
                        logger.error(
                            f"Error: Max reconnection attempts ({self.max_reconnect_attempts}) reached"
                        )
                        await self._emit_event(
                            "max_reconnects_reached",
                            {"attempts": self.current_reconnect_attempts},
                        )
                        break

            except Exception as e:
                logger.error(f"Error: Reconnection monitor error: {e}")

    async def _process_message(self, message):
        """
        Process incoming messages.

        PO шлёт итоги ордеров как Socket.IO BINARY_EVENT:
          451-["successopenOrder",{"_placeholder":true,"num":0}]
          <binary JSON>
        Раньше placeholder диспатчили без body, а binary кадр теряли — кэш зеркала пустой.
        """
        try:
            if isinstance(message, bytes):
                # Если не ждали attachment — пробуем как UTF-8 текст.
                message = message.decode("utf-8", errors="replace")

            logger.debug(f"Message: Received: {message[:100]}...")

            if message == "2":
                if self.websocket:
                    await self.websocket.send("3")
                    self.connection_stats["last_pong_time"] = datetime.now()
                    logger.debug("Ping: Pong sent")
                return

            # BINARY_EVENT header: 45<N>-[event, placeholder...]
            bin_header = self._parse_binary_event_header(message)
            if bin_header is not None:
                n_att, packet = bin_header
                self._binary_pending = {
                    "expected": n_att,
                    "packet": packet,
                    "buffers": [],
                }
                print(
                    f"[MIRROR] binary header event={packet[0] if packet else '?'} "
                    f"attachments={n_att}",
                    flush=True,
                )
                # Не диспатчим до прихода binary frames.
                return

            await self._emit_event("message_received", {"message": message})

            parsed = self._parse_socketio_event(message)
            if parsed is not None:
                await self._dispatch_socketio_event(parsed)

        except Exception as e:
            logger.error(f"Error: Error processing message: {e}")
            print(f"[WARN] KeepAlive _process_message: {e}", flush=True)

    def _parse_binary_event_header(
        self, message: str
    ) -> Optional[tuple]:
        """
        Socket.IO BINARY_EVENT: 45<count>-[...].
        Пример: 451-["successcloseOrder",{"_placeholder":true,"num":0}]
        """
        if not isinstance(message, str) or not message.startswith("45"):
            return None
        # Не путать с обычным EVENT 42.
        m = re.match(r"^45(\d+)-(\[.*\])\s*$", message, re.DOTALL)
        if not m:
            return None
        try:
            n_att = int(m.group(1))
            packet = json.loads(m.group(2))
            if not isinstance(packet, list) or n_att <= 0:
                return None
            return n_att, packet
        except (json.JSONDecodeError, ValueError, TypeError):
            return None

    async def _consume_binary_attachment(self, raw: bytes) -> None:
        """Дописываем binary frame к pending 451- и диспатчим событие."""
        pending = self._binary_pending
        if not pending:
            return
        pending["buffers"].append(raw)
        print(
            f"[MIRROR] binary chunk #{len(pending['buffers'])}/"
            f"{pending['expected']} bytes={len(raw)}",
            flush=True,
        )
        if len(pending["buffers"]) < int(pending["expected"]):
            return

        reconstructed = self._reconstruct_binary_packet(
            pending["packet"], pending["buffers"]
        )
        self._binary_pending = None
        if not reconstructed:
            return

        event_name = reconstructed[0] if reconstructed else "?"
        print(
            f"[MIRROR] binary reconstructed event={event_name} "
            f"payload_type={type(reconstructed[1]).__name__ if len(reconstructed) > 1 else '-'}",
            flush=True,
        )

        # Для совместимости с client._on_keep_alive_message — синтетический 42-кадр.
        try:
            fake_42 = "42" + json.dumps(reconstructed, ensure_ascii=False, default=str)
            await self._emit_event("message_received", {"message": fake_42})
        except Exception as exc:
            print(f"[WARN] KeepAlive fake_42 emit failed: {exc}", flush=True)

        await self._dispatch_socketio_event(reconstructed)

    def _reconstruct_binary_packet(
        self, packet: List[Any], buffers: List[bytes]
    ) -> Optional[List[Any]]:
        """Подставляем buffers[num] вместо {_placeholder:true,num:N}."""

        def walk(obj: Any) -> Any:
            if isinstance(obj, dict) and obj.get("_placeholder") is True:
                num = int(obj.get("num", 0))
                if num < 0 or num >= len(buffers):
                    return obj
                blob = buffers[num]
                # PO кладёт JSON объекта сделки в binary attachment.
                for decoder in (
                    lambda b: json.loads(b.decode("utf-8")),
                    lambda b: json.loads(b.decode("utf-8-sig")),
                    lambda b: json.loads(b),
                ):
                    try:
                        return decoder(blob)
                    except Exception:
                        continue
                return {"_raw_binary": True, "size": len(blob)}
            if isinstance(obj, list):
                return [walk(x) for x in obj]
            if isinstance(obj, dict):
                return {k: walk(v) for k, v in obj.items()}
            return obj

        try:
            out = walk(packet)
            return out if isinstance(out, list) else None
        except Exception as e:
            print(f"[WARN] KeepAlive reconstruct failed: {e}", flush=True)
            return None

    def _parse_socketio_event(self, message: str) -> Optional[List[Any]]:
        """Достаём ["eventName", payload] из обычных 42… кадров (не 451)."""
        if not message or message.startswith("45"):
            return None
        try:
            if message.startswith("42"):
                m = _SIO_EVENT_RE.match(message)
                raw = m.group(1) if m else message[2:]
                data = json.loads(raw)
                if isinstance(data, list) and len(data) >= 1:
                    return data
        except (json.JSONDecodeError, IndexError, TypeError):
            return None
        return None

    async def _dispatch_socketio_event(self, data: List[Any]) -> None:
        """Эмитим типизированные события KeepAlive → клиент / mirror hooks."""
        event_type = data[0]
        event_data: Union[Dict[str, Any], List[Any], Any] = (
            data[1] if len(data) > 1 else {}
        )

        if event_type in ("successauth", "authenticated"):
            logger.success("Success: Authentication successful")
            await self._emit_event(
                "authenticated",
                event_data if isinstance(event_data, dict) else {},
            )
        elif event_type in ("successupdateBalance", "balance_updated"):
            await self._emit_event(
                "balance_updated",
                event_data if isinstance(event_data, dict) else {},
            )
        elif event_type in ("successopenOrder", "order_opened"):
            await self._emit_event(
                "order_opened",
                event_data if isinstance(event_data, dict) else {},
            )
        elif event_type in ("successcloseOrder", "order_closed"):
            await self._emit_event(
                "order_closed",
                event_data if isinstance(event_data, dict) else {},
            )
        elif event_type == "updateClosedDeals":
            # PO часто шлёт закрытые сделки пачкой в list (не successcloseOrder).
            await self._emit_event(
                "closed_deals",
                event_data if isinstance(event_data, (dict, list)) else {},
            )
            if isinstance(event_data, list):
                await self._emit_event("order_closed", {"deals": event_data})
            elif isinstance(event_data, dict):
                deals = event_data.get("deals")
                if isinstance(deals, list):
                    await self._emit_event("order_closed", event_data)
                else:
                    await self._emit_event("order_closed", event_data)
        elif event_type == "updateHistoryNew":
            # Иногда итог приходит историей сделок, а не successcloseOrder.
            await self._emit_event(
                "history_update",
                event_data if isinstance(event_data, dict) else {},
            )
            if isinstance(event_data, dict) and isinstance(event_data.get("deals"), list):
                await self._emit_event("order_closed", event_data)
            elif isinstance(event_data, list):
                await self._emit_event("order_closed", {"deals": event_data})
        elif event_type in ("updateStream", "stream_update"):
            await self._emit_event(
                "stream_update",
                event_data if isinstance(event_data, dict) else {},
            )
        elif event_type == "updateAssets":
            await self._emit_event(
                "assets_update",
                event_data if isinstance(event_data, dict) else {},
            )
            await self._emit_event("authenticated", {"via": "updateAssets"})
        elif isinstance(event_data, (dict, list)):
            await self._emit_event("json_data", event_data)

    async def send_message(self, message: str) -> bool:
        """Send message with connection check"""
        try:
            if self.is_connected and self.websocket:
                await self.websocket.send(message)
                self.connection_stats["total_messages_sent"] += 1
                logger.debug(f"Message: Sent: {message[:50]}...")
                return True
            else:
                logger.warning("Caution: Cannot send message: not connected")
                return False
        except Exception as e:
            logger.error(f"Error: Failed to send message: {e}")
            self.is_connected = False
            return False

    def add_event_handler(self, event: str, handler: Callable):
        """Add event handler"""
        if event not in self._event_handlers:
            self._event_handlers[event] = []
        self._event_handlers[event].append(handler)

    async def _emit_event(self, event: str, data: Any):
        """Emit event to handlers"""
        if event in self._event_handlers:
            for handler in self._event_handlers[event]:
                try:
                    if asyncio.iscoroutinefunction(handler):
                        await handler(data)
                    else:
                        handler(data)
                except Exception as e:
                    logger.error(f"Error: Error in event handler for {event}: {e}")

    def _extract_region_from_url(self, url: str) -> str:
        """Extract region name from URL"""
        try:
            parts = url.split("//")[1].split(".")[0]
            if "api-" in parts:
                return parts.replace("api-", "").upper()
            elif "demo" in parts:
                return "DEMO"
            else:
                return "UNKNOWN"
        except Exception:
            return "UNKNOWN"

    def get_connection_stats(self) -> Dict[str, Any]:
        """Get detailed connection statistics"""
        return {
            **self.connection_stats,
            "is_connected": self.is_connected,
            "current_url": self.connection_info.url if self.connection_info else None,
            "current_region": self.connection_info.region
            if self.connection_info
            else None,
            "reconnect_attempts": self.current_reconnect_attempts,
            "uptime": (
                datetime.now() - self.connection_info.connected_at
                if self.connection_info and self.connection_info.connected_at
                else timedelta()
            ),
            "available_regions": len(self.available_urls),
        }

    async def connect_with_keep_alive(
        self, regions: Optional[List[str]] = None
    ) -> bool:
        """Establish a persistent connection with keep-alive, optionally using a list of regions."""
        # Optionally update available_urls if regions are provided
        if regions:
            # Assume regions are URLs or region names; adapt as needed
            self.available_urls = regions
            self.current_url_index = 0
        return await self.start_persistent_connection()

    async def disconnect(self) -> None:
        """Disconnect and clean up persistent connection."""
        await self.stop_persistent_connection()

    def get_stats(self) -> Dict[str, Any]:
        """Return connection statistics (alias for get_connection_stats)."""
        return self.get_connection_stats()


async def demo_keep_alive():
    """Demo of the keep-alive connection manager"""

    # Example complete SSID
    ssid = r'42["auth",{"session":"n1p5ah5u8t9438rbunpgrq0hlq","isDemo":1,"uid":0,"platform":1}]'

    # Create keep-alive manager
    keep_alive = ConnectionKeepAlive(ssid, is_demo=True)

    # Add event handlers
    async def on_connected(data):
        logger.success(f"Successfully: Connected to: {data}")

    async def on_reconnected(data):
        logger.success(f"Persistent: Reconnected after {data['attempt']} attempts")

    async def on_message(data):
        logger.info(f"Message: Message: {data['message'][:50]}...")

    keep_alive.add_event_handler("connected", on_connected)
    keep_alive.add_event_handler("reconnected", on_reconnected)
    keep_alive.add_event_handler("message_received", on_message)

    try:
        # Start persistent connection
        success = await keep_alive.start_persistent_connection()

        if success:
            logger.info(
                "Starting: Keep-alive connection started, will maintain connection automatically..."
            )

            # Let it run for a while to demonstrate keep-alive
            for i in range(60):  # Run for 1 minute
                await asyncio.sleep(1)

                # Print stats every 10 seconds
                if i % 10 == 0:
                    stats = keep_alive.get_connection_stats()
                    logger.info(
                        f"Statistics: Stats: Connected={stats['is_connected']}, "
                        f"Messages sent={stats['total_messages_sent']}, "
                        f"Messages received={stats['total_messages_received']}, "
                        f"Uptime={stats['uptime']}"
                    )

                # Send a test message every 30 seconds
                if i % 30 == 0 and i > 0:
                    await keep_alive.send_message('42["test"]')

        else:
            logger.error("Error: Failed to start keep-alive connection")

    finally:
        # Clean shutdown
        await keep_alive.stop_persistent_connection()


if __name__ == "__main__":
    logger.info("Testing: Testing Enhanced Keep-Alive Connection Manager")
    asyncio.run(demo_keep_alive())
