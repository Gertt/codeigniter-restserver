<?php

class RestResponse
{

	const STATUS_OK 		= "ok";
	const STATUS_REDIRECT 	= "redirect";
	const STATUS_ERROR		= "error";

	protected $status_code	= null;			// The status of the response
	protected $data			= null;			// Data object or array that contains result data. For example a list of users
	protected $response_format		= null;
	
	protected $_zlib_oc = FALSE;
	
	/**
	 * List all supported methods, the first will be the default format
	 *
	 * @var array
	 */
	protected $supported_formats = array(
		'xml' => 'application/xml',
		'json' => 'application/json',
		'jsonp' => 'application/javascript',
		'serialized' => 'application/vnd.php.serialized',
		'php' => 'text/plain',
		'html' => 'text/html',
		'csv' => 'application/csv'
	);
	
	public function __construct($data=null, $status_code = null) {
		$this->response_format = $this->detect_format();
		$this->_zlib_oc = @ini_get('zlib.output_compression');

		$this->CI =& get_instance();
		$this->CI->load->library('format');
		
		if(isset($data)) {
			$this->setData($data);
		}
		if(isset($status_code)) {
			$this->setStatusCode($status_code);
		}
	}
	
	public function setStatusCode($status_code) 
	{
		is_numeric($status_code) OR $status_code = 200;
		
		// @todo check for valid codes
	
		$this->status_code = $status_code;
	}
	
	public function getStatusCode()
	{
		return $this->status_code;
	}
		
	public function setData($data) 
	{
		$this->data = $data;
	}
	
	public function getData() 
	{
		if(!isset($this->data) || empty($this->data)) {
			return false;
		}
		return $this->data;
	}
	
	public function setSupportedFormats($formats) {
		if(!isset($formats) || !is_array($formats)) {
			$this->supported_formats = array();
		} else {
			$this->supported_formats = $formats;
		}
	}
	
	public function getSupportedFormats() {
		if(!isset($this->supported_formats) || empty($this->supported_formats)) {
			return false;
		}
		return $this->supported_formats;
	}
	
	/**
	 * Output the current response to the client
	 *
	 * @return void
	 */
	public function __toString() {
		global $CFG;
		$http_code = $this->status_code;
		$data = $this->data;
		// If data is empty and not code provide, error and bail
		if (empty($this->data) && $this->status_code === null)
		{
			$this->status_code = 404;

			//create the output variable here in the case of $this->response(array());
			$output = $data;
		}

		// Otherwise (if no data but 200 provided) or some data, carry on camping!
		else
		{
			if ($this->_zlib_oc == FALSE)
			{
				if (extension_loaded('zlib'))
				{
					if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) AND strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== FALSE)
					{
						ob_start('ob_gzhandler');
					}
				}
			}
			
			is_numeric($http_code) OR $http_code = 200;

			// If the format method exists, call and return the output in that format
			if (method_exists($this, '_format_'.$this->response_format))
			{
				// Set the correct format header
				header('Content-Type: '.$this->supported_formats[$this->response_format]);

				$output = $this->{'_format_'.$this->response_format}($data);
			}

			// If the format method exists, call and return the output in that format
			elseif (method_exists($this->CI->format, 'to_'.$this->response_format))
			{
				// Set the correct format header
				header('Content-Type: '.$this->supported_formats[$this->response_format]);

				$output = $this->CI->format->factory($data)->{'to_'.$this->response_format}();
			}

			// Format not supported, output directly
			else
			{
				$output = $data;
			}
		}

		header('HTTP/1.1: ' . $http_code);
		header('Status: ' . $http_code);

		// If zlib.output_compression is enabled it will compress the output,
		// but it will not modify the content-length header to compensate for
		// the reduction, causing the browser to hang waiting for more data.
		// We'll just skip content-length in those cases.
		if ( is_string($output) && ! $this->_zlib_oc && ! $CFG->item('compress_output') )
		{
			header('Content-Length: ' . strlen($output));
		}

		exit($output);
	}

	
	/**
	 * Detect format
	 *
	 * Detect which format should be used to output the data.
	 * 
	 * @return string The output format. 
	 */
	protected function detect_format()
	{
		$pattern = '/\.('.implode('|', array_keys($this->supported_formats)).')$/';


		// Otherwise, check the HTTP_ACCEPT (if it exists and we are allowed)
		if (isset($_SERVER['HTTP_ACCEPT']))
		{
			// Check all formats against the HTTP_ACCEPT header
			foreach (array_keys($this->supported_formats) as $format)
			{
				// Has this format been requested?
				if (strpos($_SERVER['HTTP_ACCEPT'], $format) !== FALSE)
				{
					// If not HTML or XML assume its right and send it on its way
					if ($format != 'html' AND $format != 'xml')
					{

						return $format;
					}

					// HTML or XML have shown up as a match
					else
					{
						// If it is truely HTML, it wont want any XML
						if ($format == 'html' AND strpos($_SERVER['HTTP_ACCEPT'], 'xml') === FALSE)
						{
							return $format;
						}

						// If it is truely XML, it wont want any HTML
						elseif ($format == 'xml' AND strpos($_SERVER['HTTP_ACCEPT'], 'html') === FALSE)
						{
							return $format;
						}
					}
				}
			}
		} // End HTTP_ACCEPT checking

		// Just use the default format
		return config_item('rest_default_format');
	}

	// FORMATING FUNCTIONS ---------------------------------------------------------
	// Many of these have been moved to the Format class for better separation, but these methods will be checked too

	/**
	 * Encode as JSONP
	 * 
	 * @param array $data The input data.
	 * @return string The JSONP data string (loadable from Javascript). 
	 */
	protected function _format_jsonp($data = array())
	{
		return $this->get('callback').'('.json_encode($data).')';
	}
}