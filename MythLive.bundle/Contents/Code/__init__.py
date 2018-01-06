import uuid
import platform
import datetime
import time
import json
import os
import urllib
import re

NAME = 'MythTV LiveTV'
PREFIX = '/video/mythlive'

MYTHTV_HOSTNAME = Prefs['mythtv_hostname']
utc_datetime = datetime.datetime.utcnow()
utc_datetime_cache = utc_datetime
ALL_CHANNELS = 'http://'+MYTHTV_HOSTNAME+':6544/Guide/GetProgramGuide?StartTime='+utc_datetime.strftime("%Y-%m-%dT%H:%M:%S")+'&EndTime='+(utc_datetime+datetime.timedelta(seconds=60)).strftime("%Y-%m-%dT%H:%M:%S")+"&Details=true"
ALL_RECORDINGS = 'http://'+MYTHTV_HOSTNAME+':6544/Dvr/GetRecordedList?StartIndex=1&Descending=true'
RECENT_RECORDINGS = 'http://'+MYTHTV_HOSTNAME+':6544/Dvr/GetRecordedList?StartIndex=1&Count=20&Descending=true'
VIDEO_DURATION = 14400000   # Duration for Transcoder (ms); Default = 14400000 (4 hours)


####################################################################################################
def Start():

    Plugin.AddViewGroup("InfoList", viewMode="MediaPreview", mediaType="items",type='grid',summary=1)
    ObjectContainer.title1 = NAME
    ObjectContainer.view_group='InfoList'
    HTTP.CacheTime = 0 #CACHE_1HOUR
    HTTP.CacheTime = 0
    HTTP.Headers['User-Agent'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/39.0.2171.95 Safari/537.36'
    HTTP.Headers['Accept'] = 'application/json'

####################################################################################################
@handler(PREFIX, NAME)
def MainMenu():

     return LiveTVMenu("LiveTV")

     if Prefs['mythtv_enablelivetv'] and not Prefs['mythtv_enablerecordings']:
          return LiveTVMenu("LiveTV")
     if not Prefs['mythtv_enablelivetv'] and Prefs['mythtv_enablerecordings']:
          return RecordingsMenu("LiveTV")

     oc = ObjectContainer(no_cache=True,view_group='InfoList')
     oc.add(DirectoryObject(key = Callback(LiveTVMenu, title = "Live TV"), title = "Live TV"))
     oc.add(DirectoryObject(key = Callback(Recordings, title = "Recordings"), title = "Recordings"))

     return oc

@route(PREFIX+'/livetv')
def LiveTVMenu(title):

    global ALL_CHANNELS,utc_datetime_cache

    oc = ObjectContainer(no_cache=True,view_group='InfoList')

    utc_datetime = datetime.datetime.utcnow()
    utcminutesdiff=(utc_datetime-utc_datetime_cache).total_seconds()/60

    cacheminutes=1
    if Prefs['programinfocache'].isdigit():
         cacheminutes=int(Prefs['programinfocache'])

    Log("CACHEINFO: Keep Program info for "+str(cacheminutes)+" minutes")
    Log("CACHEINFO: UTC_DATETIME="+utc_datetime.strftime("%Y-%m-%dT%H:%M:%S"))
    Log("CACHEINFO: UTC_DATETIME_CACHE="+utc_datetime_cache.strftime("%Y-%m-%dT%H:%M:%S"))
    Log("CACHEINFO: UTC MINUTE DIFFERENCE="+str(utcminutesdiff))


    if utcminutesdiff > cacheminutes:
         utc_datetime_cache = datetime.datetime.utcnow()
         ALL_CHANNELS = 'http://'+MYTHTV_HOSTNAME+':6544/Guide/GetProgramGuide?StartTime='+utc_datetime.strftime("%Y-%m-%dT%H:%M:%S")+'&EndTime='+(utc_datetime+datetime.timedelta(seconds=60)).strftime("%Y-%m-%dT%H:%M:%S")+"&Details=true"
         Log("CACHEINFO: Retrieving New Program Info : "+ALL_CHANNELS)
    else:
         Log("CACHEINFO: Retrieving Cached Program Info : "+ALL_CHANNELS)


    if 'RecentChannels' in Dict:
         Log("RecentChannels="+Dict['RecentChannels'])
    else:
         Log("No Recent Channels")

    category_string="All Channels,Channels Recently Watched,"+Prefs["mythtv_category"]
    category = category_string.split(',')
    #category = ["All Channels","Action","Adventure","Children","Comedy","Community","Crime","Documentary","Drama","Game show","Historical Drama","Horror","Music","Nature","News","Politics","Science","Sitcom","Sports event","Talk","Travel","Weather","Western"]

    for section in category:
         oc.add(DirectoryObject(key = Callback(AllChannelsSection, title = section, url=ALL_CHANNELS), title = section))

    return oc

@route(PREFIX+'/recordings')
def Recordings(title):

    global ALL_RECORDINGS,RECENT_RECORDINGS

    oc = ObjectContainer(no_cache=True,view_group='InfoList')

    oc.add(DirectoryObject(key = Callback(DisplayRecordingsSection, title = 'Recent Recordings', url=RECENT_RECORDINGS), title = 'Recent Recordings'))
    oc.add(DirectoryObject(key = Callback(DisplayRecordingsSection, title = 'Recordings By Show', url=ALL_RECORDINGS), title = 'Recordings By Show'))

    return oc

# This function pulls the sections of a video page
@route(PREFIX + '/displayrecordings')
def DisplayRecordingsSection(title, url):

    oc = ObjectContainer(title2=title,no_cache=True,view_group='InfoList')
    req = HTTP.Request(url).content
    result = JSON.ObjectFromString(req)

    Log("HTTP Reply: "+req);
    Log(json.dumps(result, indent=4, sort_keys=True));

    try: list = result['ProgramList']['Programs']
    except: list = []

    i=0
    for section in list:
        Log("Processing... "+section['Title'])
        sectiontitle=""
        if len(section['Title']) > 0:
             sectiontitle=section['Title']+" "+section['Recording']['StartTs']
             sectionsummary=section['SubTitle']
             if sectionsummary != "" and section['Description'] != "": 
                       sectionsummary=sectionsummary+" - \n"
             sectionsummary=sectionsummary+section['Description']
             sourcetitle=section['CatType']
             if sourcetitle != "" and section['Category'] != "":
                  sourcetitle=sourcetitle+" - "
             sourcetitle=sourcetitle+section['Category']

        try:
             MACHINEID = Request.Headers['X-Plex-Client-Identifier'];
             videourl='http://'+MYTHTV_HOSTNAME+'/plex-livetv-feeder.php?file='+section['FileName']+'&client='+MACHINEID+'&verbose='+str(Prefs['mythtv_verbose'])+'&srcidskip='+str(Prefs['mythtv_srcid_skiplist'])
             thumburl='http://'+MYTHTV_HOSTNAME+':6544'+section['Channel']['IconURL']
             oc.add(CreateVideoClipObject(sectiontitle,videourl,sectiontitle,sourcetitle,sectionsummary,0,thumburl,False))
        except Exception, err:
             Log('Cannot add '+sectiontitle+", "+str(err))

    return oc


@route(PREFIX + '/allchannelssection')
def AllChannelsSection(title, url):

    oc = ObjectContainer(title2=title,no_cache=True,view_group='InfoList')
    req = HTTP.Request(url).content
    result = JSON.ObjectFromString(req)

    #Log("HTTP Reply: "+req);
    #Log(json.dumps(result, indent=4, sort_keys=True));

    try: list = result['ProgramGuide']['Channels']
    except: list = []

    ralist=[]
    i=0

    if title == 'Channels Recently Watched' and 'RecentChannels' in Dict:
         ralist=Dict['RecentChannels'].split(",")

    i=0
    for section in list:
        Log("Processing... "+section['ChanNum'])
        sectiontitle=""
        if len(section['Programs']) > 0:
             sectionstarttime=datetime.datetime.strptime(section['Programs'][0]['StartTime'],'%Y-%m-%dT%H:%M:%SZ')
             sectionendtime=datetime.datetime.strptime(section['Programs'][0]['EndTime'],'%Y-%m-%dT%H:%M:%SZ')
             if title=='All Channels' or title.lower() in section['Programs'][0]['Category'].lower() or title.lower() in section['Programs'][0]['CatType'].lower():
                  sectiontitle=section['ChanNum']+" "+section['ChannelName']+" - "+section['Programs'][0]['Title']
                  sectionsummary=section['Programs'][0]['SubTitle']
                  if sectionsummary != "" and section['Programs'][0]['Description'] != "": 
                       sectionsummary=sectionsummary+" - \n"
                  sectionsummary=sectionsummary+section['Programs'][0]['Description']
                  sourcetitle=section['Programs'][0]['CatType']
                  if sourcetitle != "" and section['Programs'][0]['Category'] != "":
                       sourcetitle=sourcetitle+" / "
                  sourcetitle=sourcetitle+section['Programs'][0]['Category']
                  sourcetitle=sourcetitle+' ('+datetime_from_utc_to_local(sectionstarttime).strftime('%I:%M%p')+' - '+datetime_from_utc_to_local(sectionendtime).strftime('%I:%M%p')+')'
             elif title == 'Channels Recently Watched' and 'RecentChannels' in Dict and section['ChanNum'] in Dict['RecentChannels'].split(","):

                  sectiontitle=section['ChanNum']+" "+section['ChannelName']+" - "+section['Programs'][0]['Title']
                  sectionsummary=section['Programs'][0]['SubTitle']
                  if sectionsummary != "" and section['Programs'][0]['Description'] != "":
                       sectionsummary=sectionsummary+" - \n"
                  sectionsummary=sectionsummary+section['Programs'][0]['Description']
                  sourcetitle=section['Programs'][0]['CatType']
                  if sourcetitle != "" and section['Programs'][0]['Category'] != "":
                       sourcetitle=sourcetitle+" / "
                  sourcetitle=sourcetitle+section['Programs'][0]['Category']
                  sourcetitle=sourcetitle+' ('+datetime_from_utc_to_local(sectionstarttime).strftime('%I:%M%p')+' - '+datetime_from_utc_to_local(sectionendtime).strftime('%I:%M%p')+')'
        elif title == 'All Channels' and sectiontitle == "":
             sectiontitle=section['ChanNum']+" "+section['ChannelName']
             sectionsummary=""
             sourcetitle="";
        elif title == 'Channels Recently Watched' and sectiontitle == "" and 'RecentChannels' in Dict and section['ChanNum'] in Dict['RecentChannels'].split(","):
             sectiontitle=section['ChanNum']+" "+section['ChannelName']
             sectionsummary=""
             sourcetitle="";
        if sectiontitle == "":
             continue;

        try:
             MACHINEID = Request.Headers['X-Plex-Client-Identifier'];
             channelurl='http://'+MYTHTV_HOSTNAME+'/plex-livetv-feeder.php?chanid='+section['ChanNum']+"&client="+MACHINEID+"&verbose="+str(Prefs['mythtv_verbose'])+'&srcidskip='+str(Prefs['mythtv_srcid_skiplist'])
             thumburl='http://'+MYTHTV_HOSTNAME+':6544'+section['IconURL']
             i=i+1
             if title == 'Channels Recently Watched' and 'RecentChannels' in Dict:
                  ralist[ralist.index(section['ChanNum'])]=CreateVideoClipObject(section['ChanNum'],channelurl,sectiontitle,sourcetitle,sectionsummary,0,thumburl,False)
             else:
                  oc.add(CreateVideoClipObject(section['ChanNum'],channelurl,sectiontitle,sourcetitle,sectionsummary,0,thumburl,False))
        except Exception, err:
             Log('Cannot add '+sectiontitle+", "+str(err))

    if title == 'Channels Recently Watched' and 'RecentChannels' in Dict:
         i=0
         for ra in ralist:
              if i > 9:
                   break
              try:
                   oc.add(ralist[i])
              except:
                   Log("RaList Not Video Object, adding generic channel: "+ralist[i]+" in "+Dict['RecentChannels'])
                   MACHINEID = Request.Headers['X-Plex-Client-Identifier'];
                   channelurl='http://'+MYTHTV_HOSTNAME+'/plex-livetv-feeder.php?chanid='+str(ralist[i])+"&client="+MACHINEID+"&verbose="+str(Prefs['mythtv_verbose'])+'&srcidskip='+str(Prefs['mythtv_srcid_skiplist'])
                   oc.add(CreateVideoClipObject(str(ralist[i]),channelurl,str(ralist[i])+" - CURRENT PROGRAM UNKNOWN","","",0,"",False))
              i=i+1

    if len(oc) < 1:
        Log ('still no value for objects')
        return ObjectContainer(header="Empty", message="There are no videos to list right now.",view_group='InfoList')
    else:
         return oc


####################################################################################################
@route(PREFIX + '/createvideoclipobject', duration=int, include_container=bool)
def CreateVideoClipObject(channum,smil_url, title, source_title, summary, duration, thumb, include_container=False, **kwargs):

    if Prefs['mythtv_channels_video_codec2']:
        alt_vcodec_channels = Prefs['mythtv_channels_video_codec2'].split(",");
    else:
        alt_vcodec_channels = ['None'];

    Log ("TV channels with alternative video_codec:" + str([alt_vcodec_channels]))

    alt_codec = False;
    for chan in alt_vcodec_channels:
        if channum == chan:
            alt_codec = True;

    if alt_codec:
        vcodec = Prefs['mythtv_video_codec2']
        if vcodec == 'auto':
            vcodec = '';
    else:
        vcodec = Prefs['mythtv_video_codec']
        if vcodec == 'auto':
            vcodec = '';


    if Prefs['mythtv_channels_audio_codec2']:
        alt_acodec_channels = Prefs['mythtv_channels_audio_codec2'].split(",");
    else:
        alt_acodec_channels = ['None'];

    Log ("TV channels with alternative audio_codec:" + str([alt_acodec_channels]))


    alt_codec = False;
    for chan in alt_acodec_channels:
        if channum == chan:
            alt_codec = True;

    if alt_codec:
        acodec = Prefs['mythtv_audio_codec2']
        if acodec == 'auto':
            acodec = '';
    else:
        acodec = Prefs['mythtv_audio_codec']
        if acodec == 'auto':
            acodec = '';


    achannels = Prefs['mythtv_audio_channels']
    if achannels == 'auto':
        achannels = '';

    Log ("TV channel requested:" + channum)
    Log ("Will use video codec:'" + vcodec + "'")
    Log ("Will use audio codec:'" + acodec + "'")
    Log ("Will use audio channels:'" + achannels + "'")

    videoclip_obj = VideoClipObject(
        key = Callback(CreateVideoClipObject, channum=channum,smil_url=smil_url, title=title, source_title=source_title, summary=summary, duration=duration, thumb=thumb, include_container=True),
        rating_key = smil_url,
        title = title,
        summary = summary,
        tagline = source_title,
        source_title = source_title,
        duration = VIDEO_DURATION,
        thumb = Resource.ContentsOfURLWithFallback(url=thumb),

        items = [
            MediaObject(
                parts = [
                    PartObject(key=Callback(PlayVideo, smil_url=smil_url + "&vcodec=" + vcodec + "&acodec=" + acodec, resolution=resolution,channum=channum))
                ],
                video_codec = vcodec,
                audio_codec = acodec,
                audio_channels = achannels,
                container = 'mp2ts',
                optimized_for_streaming = True
            ) for resolution in [1080]
        ]

    )

    if include_container:
        return ObjectContainer(objects=[videoclip_obj],no_cache=True,view_group='InfoList')
    else:
        return videoclip_obj

####################################################################################################
@route(PREFIX + '/playvideo', resolution=int)
@indirect
def PlayVideo(smil_url, resolution,channum):
    if 'RecentChannels' in Dict:
        recentChannelsTMP=Dict["RecentChannels"]
        recentChannels=recentChannelsTMP.split(",")
        recentChannels.insert(0,str(channum))
        Log("Adding Channel "+channum+" to Recent List")
        rc=[]
        Dict["RecentChannels"]=','.join(sorted(set(recentChannels),key=recentChannels.index))
        Dict.Save()
    else:
            Log("Adding Channel to Recent List")
            Dict["RecentChannels"]=channum
            Dict.Save()

    return IndirectResponse(VideoClipObject, key=HTTPLiveStreamURL(smil_url))

def datetime_from_utc_to_local(utc_datetime):
    tzdiff=datetime.datetime.now()-datetime.datetime.utcnow()
    return utc_datetime+datetime.timedelta(seconds=tzdiff.seconds)
