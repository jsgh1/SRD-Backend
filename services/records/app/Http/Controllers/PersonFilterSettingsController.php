<?php
namespace App\Http\Controllers;
use App\Application\PersonFilterSettings;
use Illuminate\Http\Request;
use Srd\Access;
final class PersonFilterSettingsController {
    public function index(PersonFilterSettings $settings): array {
        return ['data'=>$settings->read(Access::require('persons.read'))];
    }
    public function update(Request $request, PersonFilterSettings $settings): array {
        $p=Access::require('persons.read');
        $data=$request->validate([
            'version'=>'required|integer|min:0','base'=>'present|array|list|max:9',
            'base.*'=>'required|string|distinct:strict|in:'.implode(',',PersonFilterSettings::BASE),
            'custom'=>'present|array|list|max:20','custom.*'=>'required|uuid|distinct:strict',
            'delegated_roles'=>'sometimes|array|list|max:4','delegated_roles.*'=>'required|string|distinct:strict|in:registrar,treasurer,auditor,viewer',
        ]);
        return $settings->update($p,$data);
    }
}
