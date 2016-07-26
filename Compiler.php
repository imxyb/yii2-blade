<?php
namespace xyb\blade;

use Yii;
use yii\base\Exception;
use yii\helpers\ArrayHelper;

/** Blade模板解析器
 * Class Compiler
 * @package xyb\blade
 */
class Compiler
{
    /** 模板路径
     * @var
     */
    protected $path;

    /** 解析者
     * @var array
     */
    protected $compilers = [
        'Extensions',
        'Statements',
        'Comments',
        'Echos',
    ];

    /** 原生输出，不转义
     * @var array
     */
    protected $rawTags = ['{!!', '!!}'];

    /** 会使用Yii的HtmlPurifier进行安全过滤
     * @var array
     */
    protected $contentTags = ['{{', '}}'];

    /**  已过滤的数据
     * @var array
     */
    protected $escapedTags = ['{{{', '}}}'];

    /** 过滤器
     * @var string
     */
    protected $echoFormat = '\yii\helpers\HtmlPurifier::process(%s)';

    /** 编译缓存目录
     * @var string
     */
    protected $cachePath = '@runtime/blade';

    /** 编译文件
     * @param null $path 文件路径
     * @throws Exception
     */
    public function compile($path = null)
    {
        if ($path) {
            $this->setPath($path);
        }

        if(!is_file($this->getPath())) {
            throw new Exception('not found this file');
        }

        $contents = $this->compileString(file_get_contents($this->getPath()));

        if (!is_null($this->cachePath)) {
            file_put_contents($this->getCompiledPath($this->getPath()), $contents);
        }
    }

    /** 获取路径
     * @return mixed
     */
    public function getPath()
    {
        return $this->path;
    }

    /** 设置路径
     * @param $path 路径
     */
    public function setPath($path)
    {
        $this->path = $path;
    }

    /** 解析内容
     * @param $value 内容
     * @return string
     */
    public function compileString($value)
    {
        $result = '';

        foreach (token_get_all($value) as $token) {
            $result .= is_array($token) ? $this->parseToken($token) : $token;
        }

        return $result;
    }

    /** 针对拆分的词法来进行不同的解析
     * @param $token
     * @return mixed
     */
    protected function parseToken($token)
    {
        list($id, $content) = $token;

        if ($id == T_INLINE_HTML) {
            foreach ($this->compilers as $type) {
                $content = $this->{"compile{$type}"}($content);
            }
        }

        return $content;
    }

    /**
     * 解析注释
     *
     * @param  string  $value
     * @return string
     */
    protected function compileComments($value)
    {
        $pattern = sprintf('/%s--(.*?)--%s/s', $this->contentTags[0], $this->contentTags[1]);

        return preg_replace($pattern, '<?php /*$1*/ ?>', $value);
    }

    /**
     * 解析输出
     *
     * @param  string  $value
     * @return string
     */
    protected function compileEchos($value)
    {
        foreach ($this->getEchoMethods() as $method => $length) {
            $value = $this->$method($value);
        }

        return $value;
    }

    /**
     * 解析方法输出
     *
     * @return array
     */
    protected function getEchoMethods()
    {
        $methods = [
            'compileRawEchos' => strlen(stripcslashes($this->rawTags[0])),
            'compileEscapedEchos' => strlen(stripcslashes($this->escapedTags[0])),
            'compileRegularEchos' => strlen(stripcslashes($this->contentTags[0])),
        ];

        uksort($methods, function ($method1, $method2) use ($methods) {
            // Ensure the longest tags are processed first
            if ($methods[$method1] > $methods[$method2]) {
                return -1;
            }
            if ($methods[$method1] < $methods[$method2]) {
                return 1;
            }

            // Otherwise give preference to raw tags (assuming they've overridden)
            if ($method1 === 'compileRawEchos') {
                return -1;
            }
            if ($method2 === 'compileRawEchos') {
                return 1;
            }

            if ($method1 === 'compileEscapedEchos') {
                return -1;
            }
            if ($method2 === 'compileEscapedEchos') {
                return 1;
            }
        });

        return $methods;
    }

    /**
     * 解析语句结构
     *
     * @param  string  $value
     * @return mixed
     */
    protected function compileStatements($value)
    {
        $callback = function ($match) {
            if (method_exists($this, $method = 'compile'.ucfirst($match[1]))) {
                $match[0] = $this->$method(ArrayHelper::getValue($match, 3));
            } elseif (isset($this->customDirectives[$match[1]])) {
                $match[0] = call_user_func($this->customDirectives[$match[1]], ArrayHelper::getValue($match, 3));
            }

            return isset($match[3]) ? $match[0] : $match[0].$match[2];
        };

        return preg_replace_callback('/\B@(\w+)([ \t]*)(\( ( (?>[^()]+) | (?3) )* \))?/x', $callback, $value);
    }

    /**
     * 解析原生输出
     *
     * @param  string  $value
     * @return string
     */
    protected function compileRawEchos($value)
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->rawTags[0], $this->rawTags[1]);

        $callback = function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

            return $matches[1] ? substr($matches[0], 1) : '<?php echo '.$this->compileEchoDefaults($matches[2]).'; ?>'.$whitespace;
        };

        return preg_replace_callback($pattern, $callback, $value);
    }

    /**
     * 解析常规输出
     *
     * @param  string  $value
     * @return string
     */
    protected function compileRegularEchos($value)
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->contentTags[0], $this->contentTags[1]);

        $callback = function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

            $wrapped = sprintf($this->echoFormat, $this->compileEchoDefaults($matches[2]));

            return $matches[1] ? substr($matches[0], 1) : '<?php echo '.$wrapped.'; ?>'.$whitespace;
        };

        return preg_replace_callback($pattern, $callback, $value);
    }

    /**
     * 解析转义过滤输出
     *
     * @param  string  $value
     * @return string
     */
    protected function compileEscapedEchos($value)
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->escapedTags[0], $this->escapedTags[1]);

        $callback = function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

            return $matches[1] ? $matches[0] : '<?php echo \yii\helpers\HtmlPurifier::process('.$this->compileEchoDefaults($matches[2]).'); ?>'.$whitespace;
        };

        return preg_replace_callback($pattern, $callback, $value);
    }

    /**
     * 解析默认输出
     *
     * @param  string  $value
     * @return string
     */
    public function compileEchoDefaults($value)
    {
        return preg_replace('/^(?=\$)(.+?)(?:\s+or\s+)(.+?)$/s', 'isset($1) ? $1 : $2', $value);
    }

    /**
     * 解析unless输出
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileUnless($expression)
    {
        return "<?php if ( ! $expression): ?>";
    }

    /**
     * 解析unless输出
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileEndunless($expression)
    {
        return '<?php endif; ?>';
    }

    /**
     * 解析else
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileElse($expression)
    {
        return '<?php else: ?>';
    }

    /**
     * 解析for
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileFor($expression)
    {
        return "<?php for{$expression}: ?>";
    }

    /**
     * 解析foreach
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileForeach($expression)
    {
        return "<?php foreach{$expression}: ?>";
    }

    /**
     * 解析break
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileBreak($expression)
    {
        return $expression ? "<?php if{$expression} break; ?>" : '<?php break; ?>';
    }

    /**
     * 解析continue
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileContinue($expression)
    {
        return $expression ? "<?php if{$expression} continue; ?>" : '<?php continue; ?>';
    }

    /**
     * 解析forelse
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileForelse($expression)
    {
        $empty = '$__empty_'.++$this->forelseCounter;

        return "<?php {$empty} = true; foreach{$expression}: {$empty} = false; ?>";
    }

    /**
     * 解析if
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileIf($expression)
    {
        return "<?php if{$expression}: ?>";
    }

    /**
     * 解析elseif
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileElseif($expression)
    {
        return "<?php elseif{$expression}: ?>";
    }

    /**
     * 解析空输出
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileEmpty($expression)
    {
        $empty = '$__empty_'.$this->forelseCounter--;

        return "<?php endforeach; if ({$empty}): ?>";
    }

    /**
     * 解析while
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileWhile($expression)
    {
        return "<?php while{$expression}: ?>";
    }

    /**
     * 解析while
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileEndwhile($expression)
    {
        return '<?php endwhile; ?>';
    }

    /**
     * 解析for
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileEndfor($expression)
    {
        return '<?php endfor; ?>';
    }

    /**
     * 解析foreach
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileEndforeach($expression)
    {
        return '<?php endforeach; ?>';
    }

    /**
     * 解析for
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileEndif($expression)
    {
        return '<?php endif; ?>';
    }

    /**
     * 解析forelse
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileEndforelse($expression)
    {
        return '<?php endif; ?>';
    }

    /**
     * 原生输出标签
     *
     * @return array
     */
    public function getRawTags()
    {
        return $this->rawTags;
    }

    /**
     * 设置原生输出标签
     *
     * @param  string  $openTag
     * @param  string  $closeTag
     * @return void
     */
    public function setRawTags($openTag, $closeTag)
    {
        $this->rawTags = [preg_quote($openTag), preg_quote($closeTag)];
    }

    /**
     * 设置内容标签
     *
     * @param  string  $openTag
     * @param  string  $closeTag
     * @param  bool    $escaped
     * @return void
     */
    public function setContentTags($openTag, $closeTag, $escaped = false)
    {
        $property = ($escaped === true) ? 'escapedTags' : 'contentTags';

        $this->{$property} = [preg_quote($openTag), preg_quote($closeTag)];
    }

    /**
     * 设置过滤标签
     *
     * @param  string  $openTag
     * @param  string  $closeTag
     * @return void
     */
    public function setEscapedContentTags($openTag, $closeTag)
    {
        $this->setContentTags($openTag, $closeTag, true);
    }

    /**
     * 获取当前内容标签
     *
     * @return string
     */
    public function getContentTags()
    {
        return $this->getTags();
    }

    /**
     * 获取当前过滤内容左右标签
     *
     * @return string
     */
    public function getEscapedContentTags()
    {
        return $this->getTags(true);
    }

    /**
     * 获取标签
     *
     * @param  bool  $escaped
     * @return array
     */
    protected function getTags($escaped = false)
    {
        $tags = $escaped ? $this->escapedTags : $this->contentTags;

        return array_map('stripcslashes', $tags);
    }

    /**
     * 设置过滤方法
     *
     * @param  string  $format
     * @return void
     */
    public function setEchoFormat($format)
    {
        $this->echoFormat = $format;
    }

    /** 获取过滤路径
     * @param $path
     * @return string
     */
    public function getCompiledPath($path)
    {
        return Yii::getAlias($this->cachePath).'/'.sha1($path).'.php';
    }

    /** 是否修改过模板
     * @param $path
     * @return bool
     */
    public function isExpired($path)
    {
        $compiled = $this->getCompiledPath($path);

        if (! $this->cachePath || ! is_file($compiled)) {
            return true;
        }

        $lastModified = filemtime($path);

        return $lastModified >= filemtime($compiled);
    }
}
