# Prototyping performance improvements for discussion and testing


TO-DO:

* wp_unique-post_slug which is called on some wp-cron.php calls relating to the action scheduler can start taking 5+ seconds to run e.g. http://cl.ly/image/3r3A3U391r0b - a quick google indicates its a known bottleneck http://wordpress.stackexchange.com/questions/119738/can-we-have-a-post-without-a-slug

Related
* https://core.trac.wordpress.org/ticket/21112
* 




