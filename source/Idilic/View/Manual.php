<?php
namespace SeanMorris\SubSpace\Idilic\View;
class Manual extends \SeanMorris\Theme\View
{
}
__halt_compiler(); ?>
SubSpace Console 0.29a - Kallisti Websockets Playground
©2018-2021 Sean Morris

Local Commands:
Local commands begin with a "/", like /login or /pub

It is recommended to use "/login" and "/register" to
perform the respective actions rather than  the bare
"login" and "register" as the UI will prompt for a
password separately rather than printing it to the
terminal.

Run "/commands" for the full list of local commands.

Server Commands
server commands are barwords like "pub", "sub" or
"unsub".

Run "commands" for the full list of server commands.

Binary Messages:
Binary messages are transmitted on channels named by
a single 16 bit value in the range of 0x0000-0xFFFF.

Binary messages will come in this format:

>> 0x00320000 02 9A DE AD AF
 or
>> 0x0000 02 9A DE AD AF

The first example signifies that it is a message that
originated from user 0x0032 and was published to channel
0x0000. This is displayed as one long header: 0x00320000.

The second example signifies that the message originated
on the server.

Run the following commands to test:
<< sub 0x0000
<< /pub 0000 01 AF A0 FA DF

Please note these numbers are hexadecimal.

Text Messages:
Text messages will come in the following format:
{
    "message": "message",
    "origin": "user",
    "originId": 50,
    "channel": "random:channel:name",
    "originalChannel": "random:*"
}

"originId" represents the user who sent the message, in
decimal.

"channel" is the channel the message was RECEIVED on.

"originalChannel" is the channel (or channel selector) th
message was PUBLISHED on.

Run the following commands to test:
<< sub random:channel:name
<< pub random:channel:name your message here

Selectors:
To be completed...

..
