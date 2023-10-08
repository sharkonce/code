<?php

namespace App\Admin\Controllers;

use App\Admin\Repositories\LuckyHistory;
use Dcat\Admin\Admin;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;

class LuckyHistoryController extends AdminController
{
    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new LuckyHistory(), function (Grid $grid) {
            $grid->model()->orderBy('id','desc');
            $grid->column('id')->sortable();
            $grid->column('user_id');
            $grid->column('first_name');
            $grid->column('lucky_id');
            $grid->column('is_thunder')->using([0 => '无', 1 => '中雷'])->label([
                'default' => 'primary', // 设置默认颜色，不设置则默认为 default
                0 => 'primary',
                1 => 'danger',
            ]);
            $grid->column('amount');
            $grid->column('lose_money');

            $grid->column('created_at')->sortable();

            $grid->filter(function (Grid\Filter $filter) {
                // 更改为 panel 布局
                $filter->panel();
                $filter->equal('user_id');
                $filter->equal('lucky_id');

            });
            // 禁用新增
            $grid->disableCreateButton();
            $grid->actions(function (\App\Admin\Actions\TextActions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();


            });
        });
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    protected function detail($id)
    {
        return Show::make($id, new LuckyHistory(), function (Show $show) {
            $show->field('id');
            $show->field('user_id');
            $show->field('lucky_id');
            $show->field('is_thunder');
            $show->field('amount');
            $show->field('lose_money');
            $show->field('first_name');
            $show->field('created_at');
            $show->field('updated_at');
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new LuckyHistory(), function (Form $form) {
            $form->display('id');
            $form->text('user_id');
            $form->text('lucky_id');
            $form->text('is_thunder');
            $form->text('amount');
            $form->text('lose_money');
            $form->text('first_name');

            $form->display('created_at');
            $form->display('updated_at');
        });
    }
}
