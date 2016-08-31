# techspace-door-php

This code is run on the Pi with `php server.php`. 

The php code connects to the local MQTT broker (mosquitto) and subscribes to some `techspace` MQTT topics.

The door rfid readers publish messages to MQTT when rfid keys are swiped. These go through mosquitto and into the php script. The php script checks rfid key access agains the local json file.

This json file is a cache of our membership data from the cloud. We cache locally here on the Pi incase internet goes out we can still access doors.

Door access and rfid attempts are published to the cloud via wordpress api.
