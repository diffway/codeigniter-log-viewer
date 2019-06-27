<?php
/**
 * Author: Seun Matt (https://github.com/SeunMatt)
 * Date: 09-Jan-18
 * Time: 4:30 AM
 */
namespace CILogViewer;

defined('BASEPATH') OR exit('No direct script access allowed');
defined('APPPATH') OR exit('Not a Code Igniter Environment');


class CILogViewer {

    private $CI;

    private $levelsIcon = [
        'INFO' => 'glyphicon glyphicon-info-sign',
        'ERROR' => 'glyphicon glyphicon-warning-sign',
        'DEBUG' => 'glyphicon glyphicon-exclamation-sign',
    ];

    private $levelClasses = [
        'INFO' => 'info',
        'ERROR' => 'danger',
        'DEBUG' => 'warning',
    ];

    public function __construct($pattern=NULL) {
        if(isset($pattern)&&is_string($pattern)) {
            $this->LOG_GLOB_PATTERN = $pattern;
        } else {
            $this->LOG_GLOB_PATTERN = 'log-*';
        }
        $this->init();
    }


    private function init() {

        $this->LOG_LINE_START_PATTERN = "/((INFO)|(ERROR)|(DEBUG)|(ALL))[\s-\d:]+(-->)/";
        $this->LOG_DATE_PATTERN = "/(\d{4,}-[\d-:]{2,})\s([\d:]{2,})([.][\d:]{2,})?/";
        $this->LOG_LEVEL_PATTERN = "/^((ERROR)|(INFO)|(DEBUG))/";

        $this->LOG_VIEW_FILE_FOLDER = APPPATH . "views/cilogviewer"; //Changes done here
        $this->LOG_VIEW_FILE_NAME = "logs.php";
        $this->LOG_VIEW_FILE_PATH = $this->LOG_VIEW_FILE_FOLDER . "/" . $this->LOG_VIEW_FILE_NAME;
        $this->CI_LOG_VIEW_FILE_PATH = "cilogviewer/logs";

        $this->MAX_LOG_SIZE = 52428800; //50MB
        $this->MAX_STRING_LENGTH = 300; //300 chars

        if(!function_exists("get_instance")) {
            throw new \Exception("This library works in a Code Igniter Project/Environment");
        }

        //initiate Code Igniter Instance
        $this->CI = &get_instance();

        $this->LOG_FOLDER_PREFIX = $this->CI->config->item('log_path');
        $this->LOG_FILE_EXTENSION= $this->CI->config->item('log_file_extension');

        //create the view file so that CI can find it
        if(!file_exists($this->LOG_VIEW_FILE_PATH)) {

            if(!is_dir($this->LOG_VIEW_FILE_FOLDER)) {
                mkdir($this->LOG_VIEW_FILE_FOLDER);
            }

            file_put_contents($this->LOG_VIEW_FILE_PATH, file_get_contents($this->LOG_VIEW_FILE_NAME, FILE_USE_INCLUDE_PATH));
        }
    }

    /*
     * This function will return the processed HTML page
     * and return it's content that can then be echoed
     *
     * @param $fileName optional base64_encoded filename of the log file to process.
     * @returns the parse view file content as a string that can be echoed
     * */
    public function showLogs() {

        if($this->CI->input->get("del")) {
            $this->deleteFiles(base64_decode($this->CI->input->get("del")));
            redirect($this->CI->uri->uri_string());
            return;
        }

        if($f = $this->CI->input->get("dl")) {
            $this->downloadFile(base64_decode($f));
            return;
        }

        $fileName = ($this->CI->input->get("f")) ? $this->CI->input->get("f") : null;

        //get the log files from the log directory
        $files = $this->getFiles();

        if(!is_null($fileName)) {
            $currentFile = $this->LOG_FOLDER_PREFIX ."/". base64_decode($fileName);
        }
        else if(is_null($fileName) && !empty($files)) {
            $currentFile = $this->LOG_FOLDER_PREFIX ."/". $files[0];
        }
        else {
            $data['logs'] = [];
            $data['files'] = [];
            $data['currentFile'] = "";
            return $this->CI->load->view($this->CI_LOG_VIEW_FILE_PATH, $data, true);
        }

        $logs = $this->processLogs($this->getLogs($currentFile));
        $data['logs'] = $logs;
        $data['files'] =  $files;
        $data['currentFile'] = basename($currentFile);
        return $this->CI->load->view($this->CI_LOG_VIEW_FILE_PATH, $data, true);
    }


    /*
     * This function will process the logs. Extract the log level, icon class and other information
     * from each line of log and then arrange them in another array that is returned to the view for processing
     *
     * @params logs. The raw logs as read from the log file
     * @return array. An [[], [], [] ...] where each element is a processed log line
     * */
    private function processLogs($logs) {

        if(is_null($logs)) {
            return null;
        }

        $superLog = [];

        foreach ($logs as $log) {

            //get the logLine Start
            $logLineStart = $this->getLogLineStart($log);

            if(!empty($logLineStart)) {
                //this is actually the start of a new log and not just another line from previous log
                $level = $this->getLogLevel($logLineStart);
                $data = [
                    "level" => $level,
                    "date" => $this->getLogDate($logLineStart),
                    "icon" => $this->levelsIcon[$level],
                    "class" => $this->levelClasses[$level],
                ];

                if(strlen($log) > $this->MAX_STRING_LENGTH) {
                    $data['content'] = substr($log, 0, $this->MAX_STRING_LENGTH);
                    $data["extra"] = substr($log, ($this->MAX_STRING_LENGTH + 1));
                } else {
                    $data["content"] = $log;
                }

                array_push($superLog, $data);

            } else if(!empty($superLog)) {
                //this log line is a continuation of previous logline
                //so let's add them as extra
                $prevLog = $superLog[count($superLog) - 1];
                $extra = (array_key_exists("extra", $prevLog)) ? $prevLog["extra"] : "";
                $prevLog["extra"] = $extra . "<br>" . $log;
                $superLog[count($superLog) - 1] = $prevLog;
            } else {
                //this means the file has content that are not logged
                //using log_message()
                //they may be sensitive! so we are just skipping this
                //other we could have just insert them like this
//               array_push($superLog, [
//                   "level" => "INFO",
//                   "date" => "",
//                   "icon" => $this->levelsIcon["INFO"],
//                   "class" => $this->levelClasses["INFO"],
//                   "content" => $log
//               ]);
            }
        }

        return $superLog;
    }


    /*
     * extract the log level from the logLine
     * @param $logLineStart - The single line that is the start of log line.
     * extracted by getLogLineStart()
     *
     * @return log level e.g. ERROR, DEBUG, INFO
     * */
    private function getLogLevel($logLineStart) {
        preg_match($this->LOG_LEVEL_PATTERN, $logLineStart, $matches);
        return $matches[0];
    }

    private function getLogDate($logLineStart) {
        preg_match($this->LOG_DATE_PATTERN, $logLineStart, $matches);
        return $matches[0];
    }

    private function getLogLineStart($logLine) {
        preg_match($this->LOG_LINE_START_PATTERN, $logLine, $matches);
        if(!empty($matches)) {
            return $matches[0];
        }
        return "";
    }

    /*
     * returns an array of the file contents
     * each element in the array is a line in the file
     * @returns array | each line of file contents is an entry in the returned array.
     * @params complete fileName
     * */
    private function getLogs($fileName) {
        $size = filesize($fileName);
        if(!$size || $size > $this->MAX_LOG_SIZE)
            return null;
        return file($fileName, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }


    /*
     * This will get all the files in the logs folder
     * It will reverse the files fetched and
     * make sure the latest log file is in the first index
     *
     * @param boolean. If true returns the basenames of the files otherwise full path
     * @returns array of file
     * */
    private function getFiles($basename = true)
    {

        $this->LOG_PATH_PATTERN = sprintf('%s/%s.%s'
            , $this->LOG_FOLDER_PREFIX
            , $this->LOG_GLOB_PATTERN
            , $this->LOG_FILE_EXTENSION
        );

        $files = glob($this->LOG_PATH_PATTERN);

        $files = array_reverse($files);
        $files = array_filter($files, 'is_file');
        if ($basename && is_array($files)) {
            foreach ($files as $k => $file) {
                $files[$k] = basename($file);
            }
        }
        return array_values($files);
    }

    /*
     * Delete one or more log file in the logs directory
     * @param filename. It can be all - to delete all log files - or specific for a file
     * */
    private function deleteFiles($fileName) {

        if($fileName == "all") {
            array_map("unlink", glob($this->LOG_PATH_PATTERN));
        }
        else {
            unlink($this->LOG_FOLDER_PREFIX . "/" . $fileName);
        }
        return;
    }

    /*
     * Download a particular file to local disk
     * @param $fileName
     * */
    private function downloadFile($fileName) {
        $file = $this->LOG_FOLDER_PREFIX . "/" . $fileName;
        if (file_exists($file)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($file).'"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        }
    }



} // class CILogViewer

