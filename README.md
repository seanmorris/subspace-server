# Subspace Server

## Websocket Server

Invoke the server in code:

```php
$server = new \SeanMorris\SubSpace\WebSocketServer;

while(true)
{
	$server->tick();
}
```

or use the `seanmorris/ids` command line tool:

```bash
idilic server
```

## Configuration

### Subspace

Subspace needs some network configuration.

You can see an example `yaml` configuration below, along with the default values of each config.

```yaml
---
subspace:
  jwtSecret: # Secret value for creating JWTs.
             # This value MUST be set.

  address: 0.0.0.0:9998 # Address:port to listen on.
                        # 0.0.0.0:9998   - Accept connections from all addresses
                        # 127.0.0.1:9998 - Accept connections only from 127.0.0.1

  messageSizeMax: 2048  # Max bytes per message (including overhead)

  stored:
    storage: file://tmp/storage-dir/ # Stored messages directory.
    messageTotalMax: 32768 # Max bytes total for stored messages.
    messageSizeMax: 2048   # Max bytes for single stored message.


  idleTimeout: 30000 # Milliseconds to wait for user inactity before disconnection.
  pingTimeout:  1000 # Milliseconds to wait for ping response before disconnection.
  netTimeout:   5000 # Milliseconns to wait for activity before sending ping.
  throttle:     8000 # MICROseconds to wait between frames.

  sleep:     500  # Milliseconds to sleep when no users connected.
  deepSleep: 2500 # Milliseconds to sleep when no users connected.
  doze:      25   # Number of times to sleep before switchin to deepSleep.
```

### Kallisti

```yaml
kallisti:
  channels: # Specify classes for channels by selector:
  	channel:     \Vendor\Package\ChannelClass
  	selector:    \Vendor\Package\ChannelClass
  	wild:*:card: \Vendor\Package\ChannelClass
...
```
