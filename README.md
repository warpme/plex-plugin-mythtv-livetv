plex-plugin-mythtv-livetv
=========================

Plugin for PLEX allowing to watch LiveTV in PLEX.
It is based on excellent unsober work from:
https://forums.plex.tv/discussion/262148/plex-mythtv-livetv-plugin/p1?new=1

Installation:
============
1.Install MythLive.bundle in PLEX plugins dir.

2.Put: plex-livetv-proxy.php file, plex-livetv-proxy.msg dir into HTTP doc root dir

3.Verify is streaming from plex-livetv-proxy.php script works OK. You can do this i.e. by:

  a\ launching 'curl -v "http://_web server IP_/plex-livetv-proxy.php?chanid=1&verbose=Debug" > /tmp/test.mpg'
  You should see progressing transfer of data. You can examine TV plabyack by playing test.mgp in i.e. VLC.

  b\ launching VLC and asking playback from URL: "http://_web server IP_/plex-livetv-proxy.php?chanid=1&verbose=Debug".
  You should see LiveTV playback on VLC.

  In case of any problems - pls go to /var/log/plex-livetv-proxy.log and see where issue is...

3.Go to PLEX, enter plugin config and setup audio/video codecs acordingly to Your TV provider.

4.Try to watch TV channel form PLEX. If there is issue - pls look at PLEX logs.

Happy watching!
