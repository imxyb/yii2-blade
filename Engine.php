<?php
namespace xyb\blade;

/** 解析引擎类
 * Class Engine
 * @package xyb\blade
 */
class Engine
{
    /** 解析器
     * @var Compiler
     */
    public $compiler;

    /**
     * Engine constructor.
     */
    public function __construct()
    {
        $this->compiler = new Compiler();
    }

    /** 解析并获取内容
     * @param $path
     * @param array $data
     * @return string
     * @throws \yii\base\Exception
     */
    public function get($path, array $data = [])
    {
        if ($this->compiler->isExpired($path)) {
            $this->compiler->compile($path);
        }

        $compiled = $this->compiler->getCompiledPath($path);

        $results = $this->evaluatePath($compiled, $data);

        return $results;
    }

    /** 获取输出
     * @param $__path
     * @param $__data
     * @return string
     */
    protected function evaluatePath($__path, $__data)
    {
        ob_start();

        extract($__data, EXTR_SKIP);

        @include $__path;

        return ltrim(ob_get_clean());
    }
}