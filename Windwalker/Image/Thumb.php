<?php
/**
 * Part of Windwalker project. 
 *
 * @copyright  Copyright (C) 2011 - 2014 SMS Taiwan, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Windwalker\Image;

use Joomla\Filesystem\Path;
use Joomla\Registry\Registry;
use Windwalker\Helper\CurlHelper;
use Windwalker\System\ExtensionHelper;

/**
 * Class Thumb
 *
 * @since 1.0
 */
class Thumb
{
	/**
	 * Property config.
	 *
	 * @var  Registry
	 */
	protected $config = null;

	/**
	 * Default image URL.
	 * Use some placeholder to replace variable.
	 * - {width}    => Image width.
	 * - {height}   => Image height.
	 * - {zc}       => Crop or not.
	 * - {q}        => Image quality.
	 * - {file_type}=> File type.
	 *
	 * @var string
	 */
	protected $defaultImage = 'http://placehold.it/{width}x{height}';

	/**
	 * Property extension.
	 *
	 * @var  string
	 */
	protected $extension;

	/**
	 * Property hashHandler.
	 *
	 * @var  callable
	 */
	protected $hashHandler = 'md5';

	/**
	 * Constructor.
	 *
	 * @param \Joomla\Registry\Registry $config
	 * @param null                      $extension
	 */
	public function __construct(Registry $config = null, $extension = null)
	{
		$config = $config ? : new Registry;
		$this->extension = $extension;

		$this->resetCachePosition();

		$this->config->merge($config);
	}

	/**
	 * Resize an image, auto catch it from remote host and generate a new thumb in cache dir.
	 *
	 * @param   string  $url       Image URL, recommend a absolute URL.
	 * @param   integer $width     Image width, do not include 'px'.
	 * @param   integer $height    Image height, do not include 'px'.
	 * @param   int     $method    Crop or not.
	 * @param   integer $q         Image quality
	 * @param   string  $file_type File type.
	 *
	 * @return  string  The cached thumb URL.
	 */
	public function resize($url = null, $width = 100, $height = 100, $method = \JImage::SCALE_INSIDE, $q = 85, $file_type = 'jpg')
	{
		if (!$url)
		{
			return $this->getDefaultImage($width, $height, $method, $q, $file_type);
		}

		$path = $this->getImagePath($url);

		try
		{
			$img = new \JImage;

			if (\JFile::exists($path))
			{
				$img->loadFile($path);
			}
			else
			{
				return $this->getDefaultImage($width, $height, $method, $q, $file_type);
			}

			// If file type not png or gif, use jpg as default.
			if ($file_type != 'png' && $file_type != 'gif')
			{
				$file_type = 'jpg';
			}

			// Using md5 hash
			$handler   = $this->hashHandler;
			$file_name = $handler($url . $width . $height . $method . $q) . '.' . $file_type;
			$file_path = $this->config['path.cache'] . '/' . $file_name;
			$file_url  = trim($this->config['url.cache'], '/') . '/' . $file_name;

			// Img exists?
			if (\JFile::exists($file_path))
			{
				return $file_url;
			}

			// Crop
			$img = $img->createThumbs($width . 'x' . $height, $method);

			// Save
			switch ($file_type)
			{
				case 'gif':
					$type = IMAGETYPE_GIF;
					break;
				case 'png':
					$type = IMAGETYPE_PNG;
					break;
				default :
					$type = IMAGETYPE_JPEG;
					break;
			}

			$img[0]->toFile($file_path, $type, array('quality' => $q));

			return $file_url;
		}
		catch (\Exception $e)
		{
			if (JDEBUG)
			{
				echo $e->getMessage();
			}

			return $this->getDefaultImage($width, $height, $method, $q, $file_type);
		}
	}

	/**
	 * Get the origin image path, if is a remote image, will store in temp dir first.
	 *
	 * @param   string $url  The image URL.
	 * @param   string $hash Not available now..
	 *
	 * @return  string  Image path.
	 */
	public function getImagePath($url, $hash = null)
	{
		$self = \JUri::getInstance();
		$url  = new \JUri($url);

		// Is same host?
		if ($self->getHost() == $url->getHost())
		{
			$url  = $url->toString();
			$path = str_replace(\JURI::root(), JPATH_ROOT . '/', $url);
			$path = \JPath::clean($path);
		}

		// No host
		elseif (!$url->getHost())
		{
			$url  = $url->toString();
			$path = \JPath::clean(JPATH_ROOT . '/' . $url);
		}

		// Other host
		else
		{
			$handler = $this->hashHandler;
			$path = $this->config['path.temp'] . '/' . $handler(basename($url)) . '.jpg';

			if (!is_file($path))
			{
				CurlHelper::download((string) $url, $path);
			}
		}

		return $path;
	}

	/**
	 * Crop image, will count image with height percentage, and crop from middle.
	 *
	 * @param   \JImage $img  A JImage object.
	 * @param   int     $width Target width.
	 * @param   int     $height Target height.
	 * @param   object  $data Image information.
	 *
	 * @return  \JImage Croped image object.
	 */
	public function crop($img, $width, $height, $data)
	{
		$ratio = $width / $height;

		$originHeight = $data->height;
		$originWidth  = $data->width;
		$originRatio  = $originWidth / $originHeight;

		$offsetX = 0;
		$offsetY = 0;

		if ($ratio > $originRatio)
		{
			$resizeWidth  = $originWidth;
			$resizeHeight = $originWidth / $ratio;

			$offsetY = ($originHeight - $resizeHeight) / 2;
		}
		else
		{
			$resizeHeight = $originHeight;
			$resizeWidth  = $originHeight * $ratio;

			$offsetX = ($originWidth - $resizeWidth) / 2;
		}

		$img = $img->crop($resizeWidth, $resizeHeight, $offsetX, $offsetY);

		return $img;
	}

	/**
	 * Set a new default image placeholder.
	 *
	 * @param   string $url Default image placeholder.
	 */
	public function setDefaultImage($url)
	{
		$this->defaultImage = $url;
	}

	/**
	 * Get default image and replace the placeholders.
	 *
	 * @param   integer $width     Image width, do not include 'px'.
	 * @param   integer $height    Image height, do not include 'px'.
	 * @param   mixed   $zc        Crop or not.
	 * @param   integer $q         Image quality
	 * @param   string  $file_type File type.
	 *
	 * @return  string  Default image.
	 */
	public function getDefaultImage($width = 100, $height = 100, $zc = 0, $q = 85, $file_type = 'jpg')
	{
		$replace['{width}']     = $width;
		$replace['{height}']    = $height;
		$replace['{zc}']        = $zc;
		$replace['{q}']         = $q;
		$replace['{file_type}'] = $file_type;

		$url = $this->defaultImage;
		$url = strtr($url, $replace);

		return $url;
	}

	/**
	 * Set cache path, and all image will cache in here.
	 *
	 * @param   string $path Cache path.
	 */
	public function setCachePath($path)
	{
		$this->config['path.cache'] = $path;
	}

	/**
	 * Set cache URL, and all image will cll from here.
	 *
	 * @param   string $url Cache URL.
	 */
	public function setCacheUrl($url)
	{
		$this->config['url.cache'] = $url;
	}

	/**
	 * Set temp path, and all remote image will store in here.
	 *
	 * @param   string  $path Temp path.
	 */
	public function setTempPath($path)
	{
		$this->config['path.temp'] = $path;
	}

	/**
	 * Set cache position, will auto set cache path, url and temp path.
	 * If position set in: "cache/thumb"
	 * - Cache path:    ROOT/cache/thumb/cache
	 * - Temp path:     ROOT/cache/thumb/temp
	 * - Cache URL:     http://your-site.com/cache/thumb/cache/
	 */
	public function setCachePosition($path)
	{
		$this->setCachePath(JPATH_ROOT . '/' . trim($path, '/') . '/cache');
		$this->setTempPath(JPATH_ROOT . '/' . trim($path, '/') . '/temp');
		$this->setCacheUrl(trim($path, '/') . '/cache');
	}

	/**
	 * Reset cache position.
	 */
	public function resetCachePosition()
	{
		if ($this->extension)
		{
			$params = ExtensionHelper::getParams($this->extension);
		}
		else
		{
			$params = new Registry;
		}

		$this->config = new Registry;

		$this->config['path.cache'] = Path::clean(JPATH_ROOT . $params->get('thumb.cache-path', '/cache/thumbs/cache'));
		$this->config['path.temp']  = Path::clean(JPATH_ROOT . $params->get('thumb.temp-path',  '/cache/thumbs/temp'));
		$this->config['url.cache']  = $params->get('thumb.cache-url', '/cache/thumbs/cache');
		$this->config['url.temp']   = $params->get('thumb.temp-url',  '/cache/thumbs/cache');
	}

	/**
	 * Delete all cache and temp images.
	 *
	 * @param   boolean $temp Is delete temp dir too?
	 */
	public function clearCache($temp = false)
	{
		if (\JFolder::exists($this->config['path.cache']))
		{
			\JFolder::delete($this->config['path.cache']);
		}

		if ($temp && \JFolder::exists($this->config['path.temp']))
		{
			\JFolder::delete($this->config['path.temp']);
		}
	}

	/**
	 * setHashHandler
	 *
	 * @param   callable $hashHandler
	 *
	 * @return  Thumb  Return self to support chaining.
	 */
	public function setHashHandler($hashHandler)
	{
		$this->hashHandler = $hashHandler;

		return $this;
	}
}
 