<?php
namespace xyb\blade;

use yii\base\View;

/** 复写Yii的ViewRenderer类
 * 用法
 * eg:
 * ```php
 *  'view' => [
 *      'class' => 'yii\web\View',
 *      'renderers' => [
 *          'blade' => [
 *              'class' => 'xyb\blade\ViewRenderer',
 *              //'cachePath' => '@runtime/blade',
 *          ],
 *       ],
 *   ],
 * ```
 * Class ViewRenderer
 * @package xyb\blade
 */
class ViewRenderer extends \yii\base\ViewRenderer
{

    /**
     * Renders a view file.
     *
     * This method is invoked by [[View]] whenever it tries to render a view.
     * Child classes must implement this method to render the given view file.
     *
     * @param View $view the view object used for rendering the file.
     * @param string $file the view file.
     * @param array $params the parameters to be passed to the view file.
     * @return string the rendering result
     */
    public function render($view, $file, $params)
    {
        $params = (array) $params;

        (new Compiler())->compile($file);

        $view->defaultExtension = 'blade';

        return (new Engine())->get($file, array_merge(['context' => $view], $params));
    }
}