<?php

namespace markhuot\CraftQL\Types;

use craft\base\Element;
use craft\elements\Asset;
use GraphQL\Error\UserError;
use Craft;
use markhuot\CraftQL\Builders\Schema;
use markhuot\CraftQL\FieldBehaviors\EntryMutationArguments;

class Mutation extends Schema {

    function boot() {

        if ($this->request->entryTypes()->all('mutate') ||
           ($this->getRequest()->token()->can('mutate:globals') && $this->request->globals()->count())) {
            $this->addField('helloWorld')
                ->description('A sample mutation. Doesn\'t actually save anything.')
                ->resolve('If this were a real mutation it would have saved to the database.');
        }

        foreach ($this->request->entryTypes()->all('mutate') as $entryType) {
            $this->addField('upsert'.$entryType->getName())
                ->type($entryType)
                ->description('Create or update existing '.$entryType->getName().'.')
                ->use(new EntryMutationArguments);
        }

        if ($this->request->globals()->count() && $this->request->token()->can('mutate:globals')) {
            /** @var \markhuot\CraftQL\Types\Globals $globalSet */
            foreach ($this->request->globals()->all() as $globalSet) {
                $upsertField = $this->addField('upsert'.$globalSet->getName().'Globals')
                    ->type($globalSet)
                    ->addArgumentsByLayoutId($globalSet->getContext()->fieldLayoutId);

                $upsertField->resolve(function ($root, $args) use ($globalSet, $upsertField) {
                        $globalSetElement = $globalSet->getContext();

                        foreach ($args as $handle => &$value) {
                            $callback = $upsertField->getArgument($handle)->getOnSave();
                            if ($callback) {
                                $value = $callback($value);
                            }
                        }

                        $globalSetElement->setFieldValues($args);
                        Craft::$app->getElements()->saveElement($globalSetElement);
                        return $globalSetElement;
                    });
            }
        }

        if ($this->request->token()->canMatch('/^mutate:users/')) {
            $updateUser = $this->addField('upsertUser')
                ->type(User::class);

            $updateUser->addIntArgument('id');
            $updateUser->addStringArgument('firstName');
            $updateUser->addStringArgument('lastName');
            $updateUser->addStringArgument('username');
            $updateUser->addStringArgument('email');
            $updateUser->addStringArgument('password');
            $updateUser->addStringArgument('photo');

            if ($this->request->token()->can('mutate:users:permissions')) {
                $updateUser->addStringArgument('permissions')->lists();
            }

            $fieldLayout = Craft::$app->getFields()->getLayoutByType(\craft\elements\User::class);
            $updateUser->addArgumentsByLayoutId($fieldLayout->id);

            $updateUser->resolve(function ($root, $args, $context, $info) use ($updateUser) {

                $values = $args;
                $token = $this->request->token();
                $new = empty($args['id']);

                if (!$new) {
                    $userId = @$args['id'];
                    unset($values['id']);

                    if($token->canNot('mutate:users:all') && $token->canNot('mutate:users:self')) {
                        throw new UserError('unauthorized');
                    }

                    $user = \craft\elements\User::find()->id($userId)->anyStatus()->one();
                    if (!$user) {
                        throw new UserError('Could not find user '.$userId);
                    }

                    if($token->canNot('mutate:users:all') && $user->id != $token->getUser()->id) {
                        throw new UserError('unauthorized');
                    }
                }
                else {
                    $user = new \craft\elements\User;
                }

                foreach (['firstName', 'lastName', 'username', 'email'] as $fieldName) {
                    if (isset($values[$fieldName])) {
                        $user->{$fieldName} = $values[$fieldName];
                        unset($values[$fieldName]);
                    }
                }

                if(isset($values['password'])) {
                    $user->newPassword = $values['password'];
                    unset($values['password']);
                }

                $permissions = [];
                if (!empty($values['permissions'])) {
                    $permissions = $values['permissions'];
                    unset($values['permissions']);
                }

                if(!empty($values['photo'])) {
                    $data = $values['photo'];

                    if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
                        $data = substr($data, strpos($data, ',') + 1);
                        $type = strtolower($type[1]); // jpg, png, gif

                        if (!in_array($type, [ 'jpg', 'jpeg', 'gif', 'png' ])) {
                            throw new \Exception('invalid image type');
                        }

                        $photo = base64_decode($data);

                        if ($data === false) {
                            throw new \Exception('base64_decode failed');
                        }
                    } else {
                        throw new \Exception('did not match data URI with image data');
                    }

                    $uploadPath = \craft\helpers\Assets::tempFilePath();
                    $fileLocation = "{$uploadPath}/user_{$user->id}_photo.{$type}";
                    file_put_contents($fileLocation, $photo);

                    Craft::$app->users->saveUserPhoto($fileLocation, $user);

                    unset($values['photo']);
                }

                foreach ($values as $handle => &$value) {
                    $callback = $updateUser->getArgument($handle)->getOnSave();
                    if ($callback) {
                        $value = $callback($value);
                    }
                }

                if (!empty($values)) {
                    $user->setFieldValues($values);
                }

                $user->setScenario(Element::SCENARIO_LIVE);

                if (!Craft::$app->elements->saveElement($user)) {
                    if (!empty($user->getErrors())) {
                        foreach ($user->getErrors() as $key => $errors) {
                            foreach ($errors as $error) {
                                throw new UserError($error);
                            }
                        }
                    }
                }

                if($new) {
                    Craft::$app->users->assignUserToDefaultGroup($user);
                }

                if (!empty($permissions)) {
                    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
                }

                return $user;
            });
        }

        if ($this->request->token()->canMatch('/^mutate:users/')) {
            $deleteUser = $this->addField('deleteUser')
                ->type(User::class);

            $deleteUser->addIntArgument('id')->nonNull();

            $deleteUser->resolve(function ($root, $args, $context, $info) use ($deleteUser) {

                $token = $this->request->token();
                $userId = $args['id'];

                if($token->canNot('mutate:users:all') && $token->canNot('mutate:users:self')) {
                    throw new UserError('unauthorized');
                }

                $user = \craft\elements\User::find()->id($userId)->anyStatus()->one();
                if (!$user) {
                    throw new UserError('Could not find user '.$userId);
                }

                if($token->canNot('mutate:users:all') && $user->id != $token->getUser()->id) {
                    throw new UserError('unauthorized');
                }

                $user->setScenario(Element::SCENARIO_LIVE);

                if (!Craft::$app->elements->deleteElement($user)) {
                    throw new UserError('delete.failed');
                }

                return $user;
            });
        }
    }

}
