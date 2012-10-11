logstreamerHTTP
===============
This tool send any input (stdin) to a distant pages as raw POST data.
Main usage is streaming web log files through network to a remote script 
that handles writing it to a secure / centralized storage.

Major features
--------------
* asynchronous 
* compression on the fly
* use perfectly valid HTTP headers, so proxy are supported
* handle all errors that can happen : 
  * remote host not available / very slow / returns 500 error
  * keeps data in memory if any error happened, but flush 
    old data if the memory limit set is reached.

Configuration
-------------
* Copy clientHttp.php and logstreamerhttp.class.php where you want (must be a path 
accessible by Apache / the software you'll use
* edit clientHttp.php and update $config array with your parameters.
* Do not forget to make clientHttp.php executable (chmod u+x)
* Use it with Apache for example by adding following line in httpd.conf : 
    CustomLog "|/path/to/clientHttp.php"

Tests
-----
Tests are available to check that the tool is working correctly.
Memory usage was also taken care of.
After reading 4GB of data, memory usage did not grow too much (still 1.5MB mem used, peak at 9.5 MB).
To check for yourself, configure target to a png file (must return 200 OK) and run this : 
cat /dev/urandom |php clientHttp.php

Ready for production ?
----------------------
Give us a few weeks ;)

TODO
----
* Handle SSL connections with full async support (still a bug in PHP about that ... read source)
* Add more compression algorithm than just gzip
* Check config in __construct()
 