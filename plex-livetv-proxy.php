<?php

//Script providing communication proxy between PLEX LiveTV plugin and MythTV.
//Script should be placed in RootDoc directory of HTTP server.
//If Your MythTV backend is on different host than http server - You need also adjust $mythtv_host variable.

//Changelog:
//v1.0:
//initial version based on unsober work
//from: https://forums.plex.tv/discussion/262148/plex-mythtv-livetv-plugin/p1?new=1
//-code cleanup
//-update MYTH_PROTO to current MythTV master (v91)
//-added detection of multirec in getting free_tuner
//-fix some bugs with hardcoded hostname
//-fix and improved logging & debug output
//
//v1.1
//-added support for different mythtv input types.
//Currently supported types are: DVBInput and MPEG2TS
//
//v1.1.1
//-added some _very basic_ handling of BE communication/operation errors
//
//v1.2
//-added list of SrcID for which associated tuners should be exluded. This is usefull
// when user wants to avoid to use tuners where some requested channels are not avaliable.
// Without filtering - if such tuner is free - mythtv will tune to channel avaliable to this tuner
// instead to requested channel. In result user will see wrong channel!
//
//v1.3
//-added simple error reporting to clients. Currently script returns HTTP 503 status and also
//sends encoded msg to PLEX for displaying to user. If anybody knows better way to transef meesage
//to PLEX played - I'll be more that welcome to implement :-)
//
//v1.3.1
//-added to error reporting implemented in v1.3 support for errors on TV channels with different audio types.
//Currently supported audio types are: mp2 and ac3
//-rename php supporting script from plex-livetv-feeder.php to plex-livetv-proxy.php.
//
//v3.0.0
//-added capability to server multiple tuner pools where every tuner pool is receiving only subsen of TV
//channels. Script will now automagically select first free tuner for tuners pool for requested channel.
//This makes SrcID exclussion obsolete and this parameter and functionality was removed.
//
//v3.1.0
//-added support for HD homerun tuners
//
//v3.2.0
//-added timeout for waiting for stream from myth. If timeout (currently $stream_timeout = 60sec) is exceeded - script
//will exit. This allowing to release recorder in myth backend



//(c) unsober, Piotr Oniszczuk(warpme@o2.pl)
$ver="3.2.0";

//Default verbosity if 'verbose' in GET isn't provided or different than 'True' or 'Debug'
//0=minimal; 1=myth PROTO comands; 2=myth PROTO and data
$verbose=0;

//Location where script will be logging.
$logfilename="/var/log/plex-livetv-proxy.log";

//MythTV backend IP and port
$mythtv_host="localhost";
$mythtv_port=6543;

// Timeout waiting for stream from MythTV backend
$stream_timeout=30;










//--------------------------------------------------------------------------------------------------------


error_reporting(E_ALL);

$hostname=gethostname();
$timeout_length=10;
$cnt=0;
$loglevel=2;
$file="";
$fullfile="";
$filesize=0;
$storage_group="";
$socket=null;
$data=null;
$monitor=0;
$mythtv_socket="";

//Default values if not provided by http GET
$readdata       = "True";
$mythtv_channel = 1;
$client         = "mythtvclient";
$content_type   = "video/mpeg";
$container      = "mpeg";
$bufsize        = 512000;
$bufsize_target = 256000;
$vcodec         = "auto";
$acodec         = "mp2";


if (@$_GET['readdata']!='')     $readdata=@$_GET['readdata'];
if (@$_GET['chanid']!='')       $mythtv_channel=$_GET['chanid'];
if (@$_GET['client']!='')       $client=$_GET['client'];
if (@$_GET['verbose']!='' && $_GET['verbose']=='True' ) $verbose=1;
if (@$_GET['verbose']!='' && $_GET['verbose']=='Debug') $verbose=2;
if (@$_GET['contenttype']!='')  $content_type=$_GET['contenttype'];
if (@$_GET['container']!='')    $container=$_GET['container'];
if (@$_GET['buffersize']!='')   $bufsize_target=$_GET['buffersize'];
if (@$_GET['vcodec']!='')       $vcodec=$_GET['vcodec'];
if (@$_GET['acodec']!='')       $acodec=$_GET['acodec'];

if ($monitor==0 && $verbose>=0) $logfile=fopen($logfilename,"w");

$hostname_string=$hostname."-".$client;

debug("MythTV LiveTV proxy for PLEX v".$ver." by: unsober, Piotr Oniszczuk (c)",0);
debug("  -URI from PLEX : ".$_SERVER["REQUEST_URI"],0);
debug("  -Backend IP    : ".$mythtv_host,0);
debug("  -Backend port  : ".$mythtv_port,0);
debug("  -Reg. channel  : ".$mythtv_channel,0);
debug("  -Client ID     : ".$client,0);
debug("  -PLEX v.codec  : ".$vcodec,0);
debug("  -PLEX a.codec  : ".$acodec,0);
debug("  -Content. type : ".$content_type,0);
debug("  -Container type: ".$container,0);
debug("  -Data chunks   : ".$bufsize_target,0);
debug("  -Stream timeout: ".$stream_timeout,0);
debug("  -Verbosity     : ".$verbose,0);



debug("Asking to connected client: ".$client,0);
init_cmd_connection();
send_cmd_message("MYTH_PROTO_VERSION 91 BuzzOff",1);
send_cmd_message("ANN Playback $hostname_string 0",1);


$input_info=send_cmd_message("GET_FREE_INPUT_INFO 0",1);

$tuner=identify_free_tuner($input_info,$mythtv_channel);
if (! $tuner) {
    return_no_tuners_error($mythtv_channel);
    debug("\n
---- Unfortunatelly no free tuner was found at Your request...
---- Script will now EXIT!\n",0);
    exit;
}

send_cmd_message('QUERY_RECORDER '.$tuner.'[]:[]SPAWN_LIVETV[]:[]'.$hostname_string.'[]:[]0[]:[]'.$mythtv_channel,1);

$tmo_data_wait=0;

while(1){
    while ( $filesize == 0 ) {
        close_data_connection();
        $recording_info=send_cmd_message('QUERY_RECORDER '.$tuner.'[]:[]GET_CURRENT_RECORDING',1);
        identify_file_and_storage_group();
        $file_info=send_cmd_message('QUERY_SG_FILEQUERY[]:[]'.$hostname.'[]:[]'.$storage_group.'[]:[]'.$fullfile,1);
        get_file_info();
        if ($filesize==0) {
            sleep(1);
            $tmo_data_wait+=1;
            if ($tmo_data_wait>=$stream_timeout) {
                close_data_connection();
                close_cmd_connection();
                return_livetv_error("timeout waiting for stream from myth",$mythtv_channel);
                debug("\n
---- Proxy waits too long (".$stream_timeout."sec) for stream from MythTV.
---- This probably means LiveTV session started, but MythTV not provides data stream to proxy.
---- For mode details pls examine MythTV backend log.
---- Script will now EXIT!...\n",0);
                exit;
            }
        }
        else {
            $tmo_data_wait=0;
            init_data_connection();
        }
        if (connection_aborted()) {
            debug("Connection to PLEX aborted...",0);
            close_data_connection();
            close_cmd_connection();
            exit;
        }
    }
    $qfiletransfer_info=send_cmd_message('QUERY_FILETRANSFER '.$mythtv_socket.'[]:[]REQUEST_BLOCK[]:[]'.$bufsize_target,2);
    get_data_size();
    get_video_data();
}

close_data_connection();
close_cmd_connection();




function send_file($filename){

    if(file_exists($filename)){

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        header('Content-Type: ' . finfo_file($finfo, $filename));
        finfo_close($finfo);

        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        header('Content-Length: ' . filesize($filename));

        ob_clean();
        flush();
        readfile($filename);
    }
    else debug("Can not find msg file called ".$filename, 0);

}

function return_no_tuners_error($mythtv_channel){
    global $acodec,$vcodec,$content_type,$container;
    //header('HTTP/1.1 503 Service Temporarily Unavailable'); PLEX not interpreting 503 in helpful way...
    header("Status: 503 No free tuners avalaible");
    header("Content-type: ".$content_type);
    header("Content-disposition: filename=mythtv-livetv-channel".$mythtv_channel.".".$container);
    header("Cache-Control:no-cache");
    if ($acodec=="mp2") send_file("plex-livetv-proxy.msg/plex_no_free_tuners_msg_1");
    if ($acodec=="ac3") send_file("plex-livetv-proxy.msg/plex_no_free_tuners_msg_2");
}

function return_livetv_error($error_code,$mythtv_channel){
    global $acodec,$vcodec,$content_type,$container;
    //header('HTTP/1.1 503 Service Temporarily Unavailable'); PLEX not interpreting 503 in helpful way...
    header("Status: 503 ".$error_code);
    header("Content-type: ".$content_type);
    header("Content-disposition: filename=mythtv-livetv-channel".$mythtv_channel.".".$container);
    header("Cache-Control:no-cache");
    if ($acodec=="mp2") send_file("plex-livetv-proxy.msg/plex_error_livetv_msg_1");
    if ($acodec=="ac3") send_file("plex-livetv-proxy.msg/plex_error_livetv_msg_2");
}

function get_data_size(){
    global $qfiletransfer_info,$bufsize,$bufsize_target;
    $qfiletransfer_info_arr=explode("[]:[]",$qfiletransfer_info);
    $bufsize=$qfiletransfer_info_arr[0];
    debug($bufsize." data ready at backend...",2);
    if ($bufsize_target!=$bufsize) {
       if ($bufsize_target>=6000) $bufsize_target-=500;
    }
    else $bufsize_target+=500;
}

function get_filetransfer_info(){
    global $filetransfer_info,$mythtv_socket;
    $filetransfer_info_arr=explode("[]:[]",$filetransfer_info);
    $mythtv_socket=$filetransfer_info_arr[1];
    if ($mythtv_socket!=0) debug("Will use MythTV socket ($mythtv_socket)",0);
    else {
        return_livetv_error("MythTV proposed socket=0",$mythtv_channel);
        debug("\n
---- Myth returns socket address=0.
---- This probably means LiveTV channel is not tunable or there was other issue with LiveTV at backend.
---- For mode details pls examine MythTV backend log.
---- Script will now EXIT!...\n",0);
    exit;
    }
}

function get_file_info(){
    global $file_info,$filesize;
    $file_info_arr=explode("[]:[]",$file_info);
    $filesize=$file_info_arr[2];
    if ($filesize!=0) debug("Data present (fileSize is $filesize)",0);
    else debug("No data yet... (fileSize is 0)",0);
}

function identify_file_and_storage_group(){
    global $recording_info,$file,$storage_group,$fullfile;
    $recording_info_arr=explode("[]:[]",$recording_info);
    $fullfile=$recording_info_arr[12];
    $file=basename($fullfile);
    $storage_group=$recording_info_arr[41];
    if ($file) debug("Storage Group ($storage_group) and File ($file) Found",0);
    else {
        return_livetv_error("MythTV provides file_size=0",$mythtv_channel);
        debug("\n
---- Myth returns empty recording filename.
---- This probably means starting LiveTV failed at backend due unavaliable channel or other issue.
---- For mode details pls examine MythTV backend log.
---- Script will now EXIT!...\n",0);
        exit;
    }
}

function identify_free_tuner($input_info,$mythtv_channel){
    //Identifies free INPUT by parsing $input_info
    //Returns first free tuner i.e. '1'
    $free_tuner=0;
    $input_info_arr=preg_split("/DVBInput|MPEG2TS|None/",$input_info);
    for ($i=1;$i<sizeof($input_info_arr);$i++){
        debug("(identify_free_tuner): parsing line ".$i." ->".$input_info_arr[$i],2);

        $sourceid=preg_split('/( |:)/',$input_info_arr[$i])[1];
        $sourceid=preg_replace('/\[|]/', '', $sourceid);
        debug("(identify_free_tuner): srcid:".$sourceid,2);

        $tuner=preg_split('/( |:)/',$input_info_arr[$i])[2];
        $tuner=preg_replace('/\[|]/', '', $tuner);
        debug("(identify_free_tuner): tuner:".$tuner,2);

        $chanid=preg_split('/( |:)/',$input_info_arr[$i])[10];
        $chanid=preg_replace('/\[|]/', '', $chanid);
        debug("(identify_free_tuner): chanid:".$chanid,2);

        if ($chanid) debug("Skipping tuner:".$tuner." (multirecords on ".$chanid.")",0);
        else {
            $channel_info=send_cmd_message("QUERY_RECORDER ".$tuner."[]:[]CHECK_CHANNEL[]:[]".$mythtv_channel,1);
            if ($channel_info==1) {
                debug("(identify_free_tuner):CHECK_CHANNEL for".$mythtv_channel." returns:".$channel_info,2);
                $free_tuner=$tuner;
                break;
            }
            else {
                debug("Skipping tuner:".$tuner." (channel NOT avaliable)",0);
            }
        }
    }
    if ($free_tuner) debug("Found free tuner:".$free_tuner,0);
    return $free_tuner;
}

function init_cmd_connection(){
    global $mythtv_host,$mythtv_port,$socket;

    $address = gethostbyname($mythtv_host);
    debug("Trying to connect:".$mythtv_host."(".$address.")",0);

    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === false) debug("socket_create() failed: reason: " . socket_strerror(socket_last_error()),0);
    else debug("Socket Created",1);

    $result = socket_connect($socket, $address, $mythtv_port);
    if ($result === false) debug("socket_connect() failed.\nReason: ($result) " . socket_strerror(socket_last_error($socket)),0);
    else debug("Connection Established",0);
}

function init_data_connection(){
    global $mythtv_host,$mythtv_port,$data,$filetransfer_info,$hostname_string,$storage_group,$file;

    $address = gethostbyname($mythtv_host);

    $data = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($data === false) debug("socket_create() failed: reason: " . socket_strerror(socket_last_error()),0);
    else debug("Socket Created",1);

    $result = socket_connect($data, $address, $mythtv_port);
    if ($result === false) debug("socket_connect() failed.\nReason: ($result) " . socket_strerror(socket_last_error($data)),0);
    else debug("Connection Established",1);

    $filetransfer_info=send_data_message('ANN FileTransfer '.$hostname_string.' 0[]:[]/'.$file.'[]:[]'.$storage_group);
    get_filetransfer_info();

}

function close_cmd_connection(){
    debug("Closing cmd socket",1);
    @socket_close($socket);
}

function close_data_connection(){
    debug("Closing data socket",1);
    @socket_close($data);
}

function send_cmd_message($val,$loglevel){
    global $socket;
    $inval = send_myth_message($socket,$val,$loglevel);
    return $inval;
}

function send_data_message($val){
    global $data;
    $inval=send_myth_message($data,$val,2);
    return $inval;
}

function send_myth_message($socket,$val,$loglevel){
    global $verbose;

    $outlen=strlen($val);
    $outmessage=sprintf("%-8d%s",$outlen,$val);
    if ($loglevel<=$verbose) mythdebugout($outmessage,1);

    socket_write($socket, $outmessage);

    $inlen=socket_read($socket,8);
    $inval=socket_read($socket,intval($inlen));
    $inmessage=sprintf("%-8d%s",$inlen,$inval);

    if ($loglevel<=$verbose) mythdebugin($inmessage,1);

    return $inval;
}

function get_video_data(){
    global $data,$bufsize,$cnt,$readdata,$filesize,$content_type,$container,$mythtv_channel;

    if (connection_aborted()) {
        debug("Connection to PLEX aborted...",0);
        close_data_connection();
        close_cmd_connection();
        exit;
    }

    $buf="";
    if (false !== ($bytes = socket_recv($data, $buf, $bufsize, MSG_WAITALL))) {
        debug("Asked backend for $bufsize bytes and received $bytes bytes from backend",1);

        if ($readdata==true) {
            if ($cnt++==0){
                debug("Starting to send data to PLEX...",0);
                header("Content-type: ".$content_type);
                header("Content-disposition: filename=mythtv-livetv-channel".$mythtv_channel.".".$container);
                header("Cache-Control:no-cache");
            }
            if (
            connection_aborted()) {
                debug("Connection to PLEX aborted...",0);
                close_data_connection();
                close_cmd_connection();
                exit;
            }
            else {
                debug("Sending data PLEX...",1);
                echo $buf;
            }
        }

        if(connection_aborted()!=True) {
            ob_flush();
            flush();
        }

    }
    else {
        debug("socket_recv() failed; reason: " . socket_strerror(socket_last_error($data)),0);
        $filesize=0;
        close_data_connection();
    }

}

function logging($val) {
    rawdebug("SYSTEM","::",$val);
}

function debug($val,$loglevel) {
    global $verbose;
    if ($loglevel<=$verbose) rawdebug("SYSTEM","::",$val);
}

function mythdebugout($val) {
    rawdebug("MYTH",">>",$val);
}

function mythdebugin($val) {
    rawdebug("MYTH","<<",$val);
}

function rawdebug($type,$dir,$val){
    global $logfile,$monitor;
    if ($monitor==0) if ($logfile!=false) fprintf($logfile,"%20s%10s %s %s\n",date("Y-m-d H:i:s"),$type,$dir,$val);
    else printf("%20s%10s %s %s\n",date("Y-m-d H:i:s"),$type,$dir,$val);
}

exit;
