<?php
/**
 * Compiles registration statistics retrieved via a JSON API and generates locally stored SVG files for caching.
 *
 * Uses SVGGraph by goat1000, which is available under the LGPL at https://github.com/goat1000/SVGGraph
 *
 * (c) 2016 by Dominik "Fenrikur" Schöner <nosecounter@fenrikur.de>
 */

namespace nosecounter;

//TODO: Add CSS-based tooltips
require_once 'SVGGraph/SVGGraph.php';

//TODO: Add theme-based backgrounds and styling (maybe additional classes required on some elements -> setting: svg_class?)
//TODO: Increase readability of overlapping elements in scatterplot

class Nosecounter {
    const NOT_AVAILABLE = 'n/a';

    /** @var string $apiUrl URL pointing to the API endpoint delivering the current stats */
    private  $apiUrl;

    /** @var string $token Token for authentication with the API */
    private $apiToken;

    /** @var int $year Year of the convention for which to generate graphics */
    private $year;

    /** @var string $archive Path to the folder containing historic data from previous years */
    private $archiveDir = '.' . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR;

    private $svgDir = '.' . DIRECTORY_SEPARATOR . 'svg' . DIRECTORY_SEPARATOR;

    /** @var int $maxYearCount Maximum number of years (subject to availability) to be shown in comparison views */
    private $maxYearCount = 5;

    /** @var string $registrationsTimestampFormat DateTime-compatible format with which timestamps for the Created field can be generated */
    private $registrationsTimestampFormat = 'Y-m-d\TH:i:s.000O';

    /** @var \DateTime $registrationsStart At which point in time should the Registrations graph be generated? */
    private $registrationsStart;

    /** @var \DateTime $regsPerMinuteStart Until which point in time should the Registrations graph be generated? */
    private $registrationsEnd;

    /** @var int $registrationsInterval Interval in seconds over which registrations should be aggregated */
    private $registrationsInterval = 60 * 60;

    /** @var int $topCountryCount Number of top countries displayed in comparison view */
    private $topCountryCount = 10;

    /** @var int $minAge Minimum age required to attend the convention as full (counting) attendee */
    private $minAge = 18;

    /** @var array $genderList List of all possible genders to be selected at registration */
    private $genderList = array('male', 'female');

    /** @var array $sponsorList List of all possible sponsor levels */
    private $sponsorList = array('normal', 'sponsor', 'supersponsor');

    /** @var array $specialInterestList List of all possible special interests selectable at registration */
    private $specialInterestList = array('animator', 'artist', 'fursuiter', 'musician');

    /** @var array $shirtSizeList List of all possible shirt sizes selectable at registration */
    private $shirtSizeList = array('XS', 'S', 'M', 'L', 'XL', 'XXL');

    private $topCountryList;

    private $svgGraphDefaultSettings = array(
        'back_colour' => '#eee', 'stroke_colour' => '#000',
        'back_stroke_width' => 0, 'back_stroke_colour' => '#eee',
        'axis_colour' => '#333', 'axis_overlap' => 2,
        'axis_font' => 'Georgia', 'axis_font_size' => 10,
        'grid_colour' => '#666', 'label_colour' => '#efefef',
        'pad_right' => 20, 'pad_left' => 20,
        'link_base' => '/', 'link_target' => '_top',
        'minimum_grid_spacing' => 20, 'semantic_classes' => TRUE,
        'legend_shadow_opacity' => 0, 'legend_position' => 'outside right 5 0',
        'auto_fit' => TRUE
    );

    private $graphWidth = 1000;
    private $graphHeight = 500;

    /** @var array Holds raw data collected from API and archived files. */
    private $data = array();

    /** @var \DateTimeImmutable $now */
    private $now;

    private $doRegistrations;


    /**
     * Converts percentage values from decimal (0.0 … 1.0) to textual representation.
     * Note: To be used with \SVGGraph's axis_text_callback_y.
     * @param float $value Decimal representation of percentage
     * @param int $precision Precision of percentage
     * @return string Textual representation with unit sign (0% … 100%)
     */
    private $axisPercentage;
    private $labelClosure;
    private $genderLabel;
    private $sponsorLabel;
    private $shirtSizeLabel;

    function __construct() {
        date_default_timezone_set('UTC');
        $this->axisPercentage = function($value, $precision = 0) {
            return round($value * 100, $precision) . "%";
        };

        $this->labelClosure = function($dataset, $key, $value) {

            //TODO: Nasty hack to get the year number back
            $year = substr($key, count($key) - 6, 4);

            $field = (isset($this->fieldList[$dataset])) ? $this->fieldList[$dataset] : Nosecounter::NOT_AVAILABLE;

            $absoluteValue = @$this->data[$year][$this->fieldName][$field];

            return round($value * 100, 1) . "% ({$absoluteValue})";
        };

        $this->genderLabel = \Closure::bind($this->labelClosure, (object) ['fieldName' => 'Gender', 'fieldList' => $this->genderList, 'data' => &$this->data]);
        $this->sponsorLabel = \Closure::bind($this->labelClosure, (object) ['fieldName' => 'Sponsor', 'fieldList' => $this->sponsorList, 'data' => &$this->data]);
        $this->shirtSizeLabel = \Closure::bind($this->labelClosure, (object) ['fieldName' => 'ShirtSize', 'fieldList' => $this->shirtSizeList, 'data' => &$this->data]);
    }

    /**
     * Generates visualisation output from the data supplied in archive files and the API with an option of integrating
     * it into a boilerplate template and writing the output to disk.
     *
     * @param string $templateFile Path to a template file. Data accessible in the template is provided in
     *                              $nosecounterData, output is expected to be found in $output.
     * @param string $outputFile Path to which generated output from the template file should be written.
     * @return object|bool|null Returns path to output file if both $templateFile and $outputFile are specified.
     * If only $templateFile is specified, the generated output will be returned.
     * If neither $templateFile nor $outputFile are given, an array containing the individual SVG strings etc. is
     * returned, identical to the one supplied for any given $templateFile.
     * Returns FALSE if an error occurred during generation.
     */
    public function generate($templateFile = null, $outputFile = null) {
        $startTime = microtime(TRUE);

        if(empty($this->registrationsStart) || empty($this->registrationsEnd) ||
                empty($this->apiUrl) || empty($this->apiToken) || empty($this->year)) {
            error_log('Not all obligatory parameters (apiUrl, apiToken, year, registrationsStart and registrationsEnd) have been set!');
            return FALSE;
        }

        $this->now = new \DateTimeImmutable("now", new \DateTimeZone('UTC'));
        $this->loadData();

        if(empty($this->data) || empty($this->data[$this->year])) {
            error_log('No data available!');
            return FALSE;
        }

        if(!is_dir('./svg')) {
            mkdir('./svg');
        }
        $nosecounterData = new \stdClass();

        $nosecounterData->year = $this->year;
        $nosecounterData->registrationsInterval = round($this->registrationsInterval / 60) . ' Minutes';
        $nosecounterData->age = $this->generateAge();
        $nosecounterData->ageComparison = $this->generateAgeComparison();
        $nosecounterData->country = $this->generateCountry();
        $nosecounterData->countryComparison = $this->generateCountryComparison();
        $nosecounterData->demographics = $this->generateDemographics();
        $nosecounterData->gender = $this->generateGender();
        $nosecounterData->genderComparison = $this->generateGenderComparison();
        $nosecounterData->registrations = $this->generateRegistrations();
        $nosecounterData->shirts = $this->generateShirts();
        $nosecounterData->sponsors = $this->generateSponsors();
        $nosecounterData->sponsorsComparison = $this->generateSponsorsComparison();
        $nosecounterData->status = $this->generateStatus();
        $nosecounterData->statusbar = $this->generateStatusBar();
        $nosecounterData->generatedIn = round((microtime(true) - $startTime)*1000, 4);
        $nosecounterData->generatedAt = $this->now;

        if($templateFile == null) {
            return $nosecounterData;
        } else {
            $output = null;
            if(!(include $templateFile) || empty($output)) {
                error_log('Template file not found or invalid!');
                return FALSE;
            }

            if($outputFile != null) {
                if (!file_exists($outputFile) || is_writable($outputFile)) {
                    $fh = fopen($outputFile, 'w');
                    $isWriteSuccessful  = fwrite($fh, $output);
                    fclose($fh);
                    if(!$isWriteSuccessful) {
                        error_log('Failed to write output to file!');
                        return FALSE;
                    } else {
                        return $outputFile;
                    }
                } else {
                    error_log("No write permissions for $outputFile!");
                }
            } else {
                return $output;
            }
        }
    }

    private function loadData() {
        foreach (scandir($this->archiveDir) as $file) {
            $filePath = $this->archiveDir . DIRECTORY_SEPARATOR . $file;
            if($file == '.' || $file == '..') {
                continue;
            }
            if (is_file($filePath) && ($fileJson = file_get_contents($filePath)) !== FALSE) {
                $fileData = json_decode($fileJson, true);
                $this->data[$fileData['Year']] = $fileData;
            } else {
                error_log("Failed to read json archive file at $filePath!");
            }
        }

        $this->doRegistrations = ($this->now >= $this->registrationsStart && $this->now <= $this->registrationsEnd) || !file_exists($this->svgDir . 'registrations.svg');
        if (($apiJson = file_get_contents("$this->apiUrl?token=$this->apiToken&year=$this->year".(($this->doRegistrations)?'&show-created=1':''))) !== FALSE) {
            $this->data[$this->year] = json_decode($apiJson, true);
        } else {
            error_log('Failed to read data from API!');
        }
        $this->doRegistrations = isset($this->data[$this->year]['Created']);

        ksort($this->data);
        $this->data = array_slice($this->data, max(0, count($this->data) - $this->maxYearCount), $this->maxYearCount, TRUE);

        foreach($this->data as $year => $yearData) {
            asort($this->data[$year]['Country']);
            $this->data[$year]['Country'] = array_reverse($this->data[$year]['Country']);
        }
    }

    private function generateAge() {
        $settings = array('grid_division_h' => 1, 'axis_text_angle_h' => -90,
            'axis_min_h' => $this->minAge);

        return $this->generateBarGraph('Age', $settings, 'age');
    }

    private function generateAgeComparison() {
        $settings = array_merge($this->svgGraphDefaultSettings,
            array('grid_division_h' => 1, 'axis_text_angle_h' => -90,
                'axis_min_h' => $this->minAge, 'legend_shadow_opacity' => 0,
                'legend_position' => 'outside right 5 0', 'legend_columns' => 1,
                'marker_type' => 'fourstar', 'marker_size' => 5,
                'pad_right' => 120));
        $values = array();

        $settings['legend_entries'] = array();

        foreach ($this->data as $year => $yearData) {
            array_push($settings['legend_entries'], "{$yearData['Convention']} ({$yearData['Year']})");
            foreach ($yearData['Age'] as $age => $ageCount) {
                $values["{$yearData['Convention']} ({$yearData['Year']})"][$age] = $ageCount;
            }
        }

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);

        $graph->Values($values);
        return $this->writeSvg('ageComparison', $graph->Fetch('MultiScatterGraph'));
    }

    private function generateCountry() {
        $settings = array();

        return $this->generateBarGraph('Country', $settings, 'country');
    }

    private function generateCountryComparison() {
        $settings = array('legend_columns' => 2, 'pad_right' => 120);

        $this->topCountryList = array_keys(array_slice($this->data[$this->year]['Country'], 0, $this->topCountryCount));

        return $this->generateGroupedComparison('Country', $settings, 'countryComparison', $this->topCountryList);
    }

    private function generateDemographics() {
        $settings = array('pad_right' => 120);

        return $this->generateGroupedComparison('SpecialInterest', $settings, 'demographics', $this->specialInterestList);
    }

    private function generateGender() {
        $settings = array();

        return $this->generatePieGraph('Gender', $settings, 'gender');
    }

    private function generateGenderComparison() {
        $settings = array('data_label_callback' => $this->genderLabel, 'pad_right' => 100);

        return $this->generateStackedComparison('Gender', $settings, 'genderComparison', $this->genderList);
    }

    private function generateRegistrations() {
        if(!$this->doRegistrations) {
            $filePath = "{$this->svgDir}registrations.svg";
            if(file_exists($filePath)) {
                return $filePath;
            }
            return false;
        }
        $settings = array_merge($this->svgGraphDefaultSettings,
            array('fill_under' => TRUE, 'datetime_keys' => TRUE,
            'axis_text_angle_h' => -90, 'marker_size' => 1,
            'line_stroke_width' => 0.5));
        $values = array();
        $aggregateInterval = new \DateInterval("PT{$this->registrationsInterval}S");
        $aggregatedCount = 0;
        $lastDate = null;
        /** @var \DateTimeImmutable $lastInterval */
        $lastInterval = null;

        $values_raw = $this->data[$this->year]['Created'];

        foreach ($values_raw as $date => $value) {
            $dateTime = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));

            if($dateTime >= $this->registrationsStart && $dateTime <= $this->registrationsEnd) {
                if(!isset($aggregateStart)) {
                    $aggregateStart = $this->alignToInterval($dateTime, $this->registrationsInterval);
                }

                if($dateTime->sub($aggregateInterval) >= $aggregateStart) {

                    // Cap intervals with zero values if there is a gap between them
                    if(!array_key_exists($aggregateStart->format($this->registrationsTimestampFormat), $values)) {
                        // Check if the gap spans more than a single interval and cap previous interval if necessary
                        if(isset($lastInterval) && $aggregateStart >= $lastInterval) {
                            $values[$lastInterval->add($aggregateInterval)->format($this->registrationsTimestampFormat)] = 0;
                        }

                        // Cap new interval
                        $values[$aggregateStart->format($this->registrationsTimestampFormat)] = 0;
                    }

                    // Store aggregated value for current interval
                    $lastInterval = $aggregateStart->add($aggregateInterval);
                    $values[$lastInterval->format($this->registrationsTimestampFormat)] = $aggregatedCount;

                    // Reset aggregation for new interval
                    $aggregatedCount = $value;
                    $aggregateStart = $this->alignToInterval($dateTime, $this->registrationsInterval);
                } else {
                    $aggregatedCount += $value;
                }
                $lastDate = $dateTime;
            }
        }

        if(isset($lastDate)) {
            if(!isset($aggregateStart)) {
                $aggregateStart = $this->alignToInterval($lastDate, $this->registrationsInterval);
            }

            // Cap intervals with zero values if there is a gap between them
            if(!array_key_exists($aggregateStart->format($this->registrationsTimestampFormat), $values)) {
                // Check if the gap spans more than a single interval and cap previous interval if necessary
                if(isset($lastInterval) && $aggregateStart >= $lastInterval) {
                    $values[$lastInterval->add($aggregateInterval)->format($this->registrationsTimestampFormat)] = 0;
                }

                // Cap new interval
                $values[$aggregateStart->format($this->registrationsTimestampFormat)] = 0;
            }

            $values[$lastDate->format($this->registrationsTimestampFormat)] = $aggregatedCount;

        }

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);
        $graph->colours = array(array('red:0.5', 'yellow:0.5'));
        $graph->Values($values);
        return $this->writeSvg('registrations', $graph->Fetch('LineGraph'));
    }

    private function generateShirts() {
        $settings = array('legend_columns' => 2, 'data_label_callback' => $this->shirtSizeLabel,
            'pad_right' => 130);

        return $this->generateStackedComparison('ShirtSize', $settings, 'shirts', $this->shirtSizeList);
    }

    private function generateSponsors() {
        $settings = array();

        return $this->generatePieGraph('Sponsor', $settings, 'sponsors');
    }

    private function generateSponsorsComparison() {
        $settings = array('data_label_callback' => $this->sponsorLabel, 'pad_right' => 140);

        return $this->generateStackedComparison('Sponsor', $settings, 'sponsorsComparison', $this->sponsorList);
    }

    private function generateStatus() {
        $settings = array();

        return $this->generateBarGraph('Status', $settings, 'status');
    }

    private function generateStatusBar() {
        $statusBar = '|';
        foreach ($this->data[$this->year]['Status'] as $status => $count) {
            $statusBar .= " $status: $count |";
        }

        return $statusBar;
    }

    private function generateBarGraph($fieldName, $settings, $fileName) {
        $settings = array_merge($this->svgGraphDefaultSettings, $settings);
        $values = $this->data[$this->year][$fieldName];

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);

        $graph->Values($values);
        return $this->writeSvg($fileName, $graph->Fetch('BarGraph'));
    }

    private function generateGroupedComparison($fieldName, $settings, $fileName, $legendEntries) {
        $settings = array_merge($this->svgGraphDefaultSettings, $settings);
        $settings['legend_entries'] = $legendEntries;
        $values = array();

        foreach ($this->data as $year => $yearData) {
            foreach ($legendEntries as $entry) {
                $values[$entry]["{$yearData['Convention']} ({$yearData['Year']})"] = @$yearData[$fieldName][$entry];
            }
        }

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);
        $graph->Values($values);
        return $this->writeSvg($fileName, $graph->Fetch('GroupedBarGraph'));
    }

    private function generatePieGraph($fieldName, $settings, $fileName) {
        //TODO: Label callback to show percentage as well as absolute count
        $settings = array_merge($this->svgGraphDefaultSettings, array('show_label_amount' => TRUE), $settings);
        $values = $this->data[$this->year][$fieldName];

        $totalCount = 0;
        foreach ($values as $value) {
            $totalCount += $value;
        }
        $delta = $this->data[$this->year]['TotalCount'] - $totalCount;
        if ($delta > 0) {
            $this->data[$this->year][$fieldName][Nosecounter::NOT_AVAILABLE] = $delta;
            $values[Nosecounter::NOT_AVAILABLE] = $delta;
        }

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);

        $graph->Values($values);
        return $this->writeSvg($fileName, $graph->Fetch('ExplodedPieGraph'));
    }

    private function generateStackedComparison($fieldName, $settings, $fileName, $legendEntries) {
        $settings = array_merge($this->svgGraphDefaultSettings, array('show_data_labels' => TRUE, 'data_label_position' => 'top',
            'data_label_colour' => '#efefef', 'axis_text_callback_y' => $this->axisPercentage), $settings);
        $values = array();
        array_pad($values, count($legendEntries), array());

        $hasUnknown = FALSE;
        foreach ($this->data as $year => $yearData) {
            $yearCount = 0;
            foreach ($legendEntries as $entry) {
                $value = isset($yearData[$fieldName][$entry]) ? $yearData[$fieldName][$entry] : 0;
                $yearCount += $value;
                $values[$entry]["{$yearData['Convention']} ({$yearData['Year']})"] = $value / $yearData['TotalCount'];
            }

            $yearUnknown = $yearData['TotalCount'] - $yearCount;
            if ($yearUnknown > 0) {
                $this->data[$year][$fieldName][Nosecounter::NOT_AVAILABLE] = $yearUnknown;
                $values[Nosecounter::NOT_AVAILABLE]["{$yearData['Convention']} ({$yearData['Year']})"] = $yearUnknown / $yearData['TotalCount'];
                $hasUnknown = TRUE;
            }
        }

        if (!$hasUnknown) {
            $settings['legend_entries'] = $legendEntries;
        } else {
            $settings['legend_entries'] = array_merge($legendEntries, array(Nosecounter::NOT_AVAILABLE));
        }

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);

        $graph->Values($values);
        return $this->writeSvg($fileName, $graph->Fetch('StackedBarGraph'));
    }

    /**
     * Writes the SVG code in $svg to $fileName.
     * @param string $fileName Target file to which SVG code should be written.
     * @param string $svg SVG code to be written to file.
     * @return bool|string
     */
    private function writeSvg($fileName, $svg) {
        $filePath = "{$this->svgDir}{$fileName}.svg";
        if(is_writable($this->svgDir) && (!file_exists($filePath) || is_writable($filePath))) {
            $file = fopen($filePath, 'w');
            if(!fwrite($file, $svg)) {
                error_log("Failed to write SVG to $filePath!");
            }
            fclose($file);
            return "{$filePath}?t={$this->now->getTimestamp()}";
        } else {
            error_log("No write permissions for $filePath!");
        }

        return false;
    }

    /**
     * Creates a new DateTimeImmutable aligned to the given interval (e.g. 10:03:00 aligned to an interval of
     * 5 minutes would result in 10:00:00, whereas 10:05:00 would remain unmodified).
     * @param \DateTimeImmutable $dateTime Date time object to be aligned
     * @param int $interval Interval in seconds to which $dateTime should be aligned to
     * @return \DateTimeImmutable|bool Newly created DateTimeImmutable aligned to given $interval or FALSE on failure.
     */
    private function alignToInterval(\DateTimeImmutable $dateTime, $interval) {
        return $dateTime->sub(new \DateInterval('PT' . $dateTime->getTimestamp() % $interval . 'S'));
    }

    /**
     * @see Nosecounter::$apiUrl
     * @return string
     */
    public function getApiUrl() {
        return $this->apiUrl;
    }

    /**
     * @see Nosecounter::$apiUrl
     * @param string $apiUrl
     * @return Nosecounter
     */
    public function setApiUrl($apiUrl) {
        if(empty($apiUrl)) {
            throw new \InvalidArgumentException('API URL may not be empty!');
        }

        $this->apiUrl = $apiUrl;
        return $this;
    }

    /**
     * @see Nosecounter::$apiToken
     * @return string
     */
    public function getApiToken() {
        return $this->apiToken;
    }

    /**
     * @see Nosecounter::$apiToken
     * @param string $apiToken
     * @return Nosecounter
     */
    public function setApiToken($apiToken) {
        $this->apiToken = $apiToken;
        return $this;
    }

    /**
     * @see Nosecounter::$year
     * @return int
     */
    public function getYear() {
        return $this->year;
    }

    /**
     * @see Nosecounter::$year
     * @param int $year
     * @return Nosecounter
     */
    public function setYear($year) {
        if($year <= 0) {
            throw new \InvalidArgumentException('Year must be > 0!');
        }

        $this->year = $year;
        return $this;
    }

    /**
     * @see Nosecounter::$archiveDir
     * @return string
     */
    public function getArchiveDir() {
        return $this->archiveDir;
    }

    /**
     * @see Nosecounter::$archiveDir
     * @param string $archiveDir
     * @return Nosecounter
     */
    public function setArchiveDir($archiveDir) {
        if(!is_dir($archiveDir) || !is_readable($archiveDir)) {
            error_log("The archive directory at $archiveDir does not exist or isn't readable!");
        }

        // Force $archiveDir to terminate with a DIRECTORY_SEPARATOR
        $this->archiveDir = $archiveDir . (substr_compare($archiveDir, DIRECTORY_SEPARATOR, -1, 1) ? '' : DIRECTORY_SEPARATOR);
        return $this;
    }

    /**
     * @see Nosecounter::$maxYearCount
     * @return int
     */
    public function getMaxYearCount() {
        return $this->maxYearCount;
    }

    /**
     * @see Nosecounter::$maxYearCount
     * @param int $maxYearCount
     * @return Nosecounter
     */
    public function setMaxYearCount($maxYearCount) {
        if($maxYearCount <= 0) {
            throw new \InvalidArgumentException('maxYearCount must be > 0!');
        } else {
            $this->maxYearCount = $maxYearCount;
        }

        return $this;
    }

    /**
     * @see Nosecounter::$registrationsTimestampFormat
     * @return string
     */
    public function getRegistrationsTimestampFormat() {
        return $this->registrationsTimestampFormat;
    }

    /**
     * @see Nosecounter::$registrationsTimestampFormat
     * @param string $registrationsTimestampFormat
     * @return Nosecounter
     */
    public function setRegistrationsTimestampFormat($registrationsTimestampFormat) {
        if(empty($registrationsTimestampFormat)) {
            //TODO: Validate timestamp format?
            throw new \InvalidArgumentException('registrationsTimestampFormat format may not be empty!');
        } else {
            $this->registrationsTimestampFormat = $registrationsTimestampFormat;
        }
        return $this;
    }

    /**
     * @see Nosecounter::$registrationsStart
     * @return \DateTime
     */
    public function getRegistrationsStart() {
        return $this->registrationsStart;
    }

    /**
     * @see Nosecounter::$registrationsStart
     * @param \DateTime $registrationsStart
     * @return Nosecounter
     */
    public function setRegistrationsStart($registrationsStart) {
        if(empty($registrationsStart)) {
            throw new \InvalidArgumentException('registrationsStart may not be empty!');
        } elseif(!empty($this->registrationsStart) && $registrationsStart > $this->registrationsEnd) {
            throw new \InvalidArgumentException('registrationsStart may not be set to a value before registrationsEnd!');
        }

        $this->registrationsStart = $registrationsStart;
        return $this;
    }

    /**
     * @see Nosecounter::$registrationsStart
     * @return \DateTime
     */
    public function getRegistrationsEnd() {
        return $this->registrationsEnd;
    }

    /**
     * @see Nosecounter::$registrationsEnd
     * @param \DateTime $registrationsEnd
     * @return Nosecounter
     */
    public function setRegistrationsEnd($registrationsEnd) {
        if(empty($registrationsEnd)) {
            throw new \InvalidArgumentException('registrationsEnd may not be empty!');
        } elseif(!empty($this->registrationsEnd) && $registrationsEnd < $this->registrationsStart) {
            throw new \InvalidArgumentException('registrationsEnd may not be set to a value before registrationsStart!');
        }

        $this->registrationsEnd = $registrationsEnd;
        return $this;
    }

    /**
     * @see Nosecounter::$registrationsInterval
     * @return int
     */
    public function getRegistrationsInterval() {
        return $this->registrationsInterval;
    }

    /**
     * @see Nosecounter::$registrationsInterval
     * @param int $registrationsInterval
     * @return Nosecounter
     */
    public function setRegistrationsInterval($registrationsInterval) {
        if($registrationsInterval <= 0) {
            throw new \InvalidArgumentException('registrationsInterval must be > 0!');
        }

        $this->registrationsInterval = $registrationsInterval;
        return $this;
    }

    /**
     * @see Nosecounter::$topCountryCount
     * @return int
     */
    public function getTopCountryCount() {
        return $this->topCountryCount;
    }

    /**
     * @see Nosecounter::$topCountryCount
     * @param int $topCountryCount
     * @return Nosecounter
     */
    public function setTopCountryCount($topCountryCount) {
        if($topCountryCount <= 0) {
            throw new \InvalidArgumentException('topCountryCount must be > 0!');
        }

        $this->topCountryCount = $topCountryCount;
        return $this;
    }

    /**
     * @see Nosecounter::$minAge
     * @return int
     */
    public function getMinAge() {
        return $this->minAge;
    }

    /**
     * @see Nosecounter::$minAge
     * @param int $minAge
     * @return Nosecounter
     */
    public function setMinAge($minAge) {
        if($minAge <= 0) {
            throw new \InvalidArgumentException('minAge must be > 0!');
        }

        $this->minAge = $minAge;
        return $this;
    }

    /**
     * @see Nosecounter::$genderList
     * @return array
     */
    public function getGenderList() {
        return $this->genderList;
    }

    /**
     * @see Nosecounter::$genderList
     * @param array $genderList
     * @return Nosecounter
     */
    public function setGenderList($genderList) {
        if(empty($genderList)) {
            throw new \InvalidArgumentException('genderList may not be empty!');
        }

        $this->genderList = $genderList;
        return $this;
    }

    /**
     * @see Nosecounter::$sponsorList
     * @return array
     */
    public function getSponsorList() {
        return $this->sponsorList;
    }

    /**
     * @see Nosecounter::$sponsorList
     * @param array $sponsorList
     * @return Nosecounter
     */
    public function setSponsorList($sponsorList) {
        if(empty($sponsorList)) {
            throw new \InvalidArgumentException('sponsorList may not be empty!');
        }

        $this->sponsorList = $sponsorList;
        return $this;
    }

    /**
     * @see Nosecounter::$specialInterestList
     * @return array
     */
    public function getSpecialInterestList() {
        return $this->specialInterestList;
    }

    /**
     * @see Nosecounter::$specialInterestList
     * @param array $specialInterestList
     * @return Nosecounter
     */
    public function setSpecialInterestList($specialInterestList) {
        if(empty($specialInterestList)) {
            throw new \InvalidArgumentException('specialInterestList may not be empty!');
        }

        $this->specialInterestList = $specialInterestList;
        return $this;
    }

    /**
     * @see Nosecounter::$shirtSizeList
     * @return array
     */
    public function getShirtSizeList() {
        return $this->shirtSizeList;
    }

    /**
     * @see Nosecounter::$shirtSizeList
     * @param array $shirtSizeList
     * @return Nosecounter
     */
    public function setShirtSizeList($shirtSizeList) {
        if(empty($shirtSizeList)) {
            throw new \InvalidArgumentException('shirtSizeList may not be empty!');
        }

        $this->shirtSizeList = $shirtSizeList;
        return $this;
    }

    /**
     * @return array
     */
    public function getSvgGraphDefaultSettings() {
        return $this->svgGraphDefaultSettings;
    }

    /**
     * Merges the given settings array with the pre-existing default settings for SVGGraph.
     *
     * @param array $svgGraphDefaultSettings
     * @return Nosecounter
     */
    public function setSvgGraphDefaultSettings($svgGraphDefaultSettings) {
        $this->svgGraphDefaultSettings = array_merge($this->svgGraphDefaultSettings, $svgGraphDefaultSettings);
        return $this;
    }

    /**
     * @return string
     */
    public function getSvgDir() {
        return $this->svgDir;
    }

    /**
     * @param string $svgDir
     * @return Nosecounter
     */
    public function setSvgDir($svgDir) {
        if(empty($svgDir)) {
            throw new \InvalidArgumentException('svgDir may not be empty!');
        }

        // Force $svgDir to terminate with a DIRECTORY_SEPARATOR
        $this->svgDir = $svgDir . (substr_compare($svgDir, DIRECTORY_SEPARATOR, -1, 1) ? '' : DIRECTORY_SEPARATOR);
        return $this;
    }

    /**
     * @return int
     */
    public function getGraphWidth() {
        return $this->graphWidth;
    }

    /**
     * @param int $graphWidth
     * @return Nosecounter
     */
    public function setGraphWidth($graphWidth) {
        if($graphWidth <= 0) {
            throw new \InvalidArgumentException('graphWidth must be > 0!');
        }

        $this->graphWidth = $graphWidth;
        return $this;
    }

    /**
     * @return int
     */
    public function getGraphHeight() {
        return $this->graphHeight;
    }

    /**
     * @param int $graphHeight
     * @return Nosecounter
     */
    public function setGraphHeight($graphHeight) {
        if($graphHeight <= 0) {
            throw new \InvalidArgumentException('graphHeight must be > 0!');
        }

        $this->graphHeight = $graphHeight;
        return $this;
    }
}
