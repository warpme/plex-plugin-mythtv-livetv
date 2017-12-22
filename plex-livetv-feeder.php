<?php

//Script supporting PLEX LiveTV plugin.
//Script should be placed in RootDoc directory of HTTP server.
//If Your MythTV backend is ob different host than http server - You need also adjust $mythtv_host variable.

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


//(c)unsober, Piotr Oniszczuk(warpme@o2.pl)
$ver="1.0.1";

//Default verbosity if 'verbose' in GET isn't provided or different than 'True' or 'Debug'
//0=minimal; 1=myth PROTO comands; 2=myth PROTO and data
$verbose=0;

//Location where script will be logging.
$logfilename="/var/log/plex-livetv-feeder.log";

//MythTV backend IP and port
$mythtv_host="localhost";
$mythtv_port=6543;









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

if (@$_GET['readdata']!='')     $readdata=@$_GET['readdata'];
if (@$_GET['chanid']!='')       $mythtv_channel=$_GET['chanid'];
if (@$_GET['client']!='')       $client=$_GET['client'];
if (@$_GET['verbose']!='' && $_GET['verbose']=='True' ) $verbose=1;
if (@$_GET['verbose']!='' && $_GET['verbose']=='Debug') $verbose=2;
if (@$_GET['content_type']!='') $content_type=$_GET['contenttype'];
if (@$_GET['container']!='')    $container=$_GET['container'];
if (@$_GET['buffersize']!='')   $bufsize_target=$_GET['buffersize'];

$options = getopt("h::l::c::m::p::t::v::d::");
parse_args();

if ($monitor==0 && $verbose>=0) $logfile=fopen($logfilename,"w");

$hostname_string=$hostname."-".$client;

debug("MythTV LiveTV feeder v".$ver." (c)unsober, Piotr Oniszczuk",0);
debug("  -Backend IP    : ".$mythtv_host,0);
debug("  -Backend port  : ".$mythtv_port,0);
debug("  -Reg. channel  : ".$mythtv_channel,0);
debug("  -Client ID     : ".$client,0);
debug("  -Content. type : ".$content_type,0);
debug("  -Container type: ".$container,0);
debug("  -Data chunks   : ".$bufsize_target,0);
debug("  -Verbosity     : ".$verbose,0);



debug("Asking to connected client: ".$client,0);
init_cmd_connection();
send_cmd_message("MYTH_PROTO_VERSION 91 BuzzOff",1);
send_cmd_message("ANN Playback $hostname_string 0",1);

$input_info=send_cmd_message("GET_FREE_INPUT_INFO 0",1);
//debug("Returned INPUT Info: ".$input_info,2);

$tuner=identify_free_tuner($input_info);
if (! $tuner) {
    debug("No free tuner found...Exting",0);
    exit;
}

send_cmd_message('QUERY_RECORDER '.$tuner.'[]:[]SPAWN_LIVETV[]:[]'.$hostname_string.'[]:[]0[]:[]'.$mythtv_channel,1);

while(1){
    while ( $filesize == 0 ) {
        close_data_connection();
        $recording_info=send_cmd_message('QUERY_RECORDER '.$tuner.'[]:[]GET_CURRENT_RECORDING',1);
        identify_file_and_storage_group();
        $file_info=send_cmd_message('QUERY_SG_FILEQUERY[]:[]'.$hostname.'[]:[]'.$storage_group.'[]:[]'.$fullfile,1);
        get_file_info();
        if ($filesize==0) sleep(1);
        else init_data_connection();
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
    debug("Storage Group ($storage_group) and File ($file) Found",0);
}

function identify_free_tuner($input_info){
    //Identifies free INPUT by parsing $input_info
    //Returns first free tuner i.e. '1'
    $input_info_arr=explode("DVBInput",$input_info);
    for ($i=1;$i<sizeof($input_info_arr);$i++){
        debug("(identify_free_tuner): parsing line ".$i." ->".$input_info_arr[$i],2);
        $tuner=preg_split('/( |:)/',$input_info_arr[$i])[2];
        $tuner=preg_replace('/\[|]/', '', $tuner);
        debug("(identify_free_tuner): identified tuner:".$tuner,2);
        $mplex=preg_split('/( |:)/',$input_info_arr[$i])[10];
        $mplex=preg_replace('/\[|]/', '', $mplex);
        debug("(identify_free_tuner): identified mplex:".$mplex,2);
        if ($mplex) debug("Skipping tuner".$tuner."(multirec with ".$mplex." mplex)",1);
        else break;
    }
    if ($tuner) debug("Found free tuner:".$tuner,0);
    return $tuner;
}

function parse_args(){
    global $options,$h,$m,$p,$t,$c,$v,$m,$hostname,$mythtv_host,$mythtv_port,$mythtv_channel,$timeout_length,$verbose,$monitor;
    if (@$options["h"]!="") $hostname=$options["h"];
    if (@$options["m"]!="") $mythtv_host=$options["m"];
    if (@$options["p"]!="") $mythtv_port=$options["p"];
    if (@$options["t"]!="") $timeout_length=$options["t"];
    if (@$options["c"]!="") $mythtv_channel=$options["c"];
    if (@$options["v"]!="") $verbose=$options["v"];
    if (@$options["d"]!="") $monitor=$options["d"];
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
                debug("Sending data PLEX",1);
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
