# Graph Report - .  (2026-07-23)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 720 nodes · 1088 edges · 117 communities (98 shown, 19 thin omitted)
- Extraction: 92% EXTRACTED · 8% INFERRED · 0% AMBIGUOUS · INFERRED: 90 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `5078a696`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Product
- Illuminate\Database\Eloquent\Model
- main.py
- Detection
- ArmMqttService
- TrainingRun
- User
- devDependencies
- Illuminate\Database\Eloquent\Factories\Factory
- Illuminate\Foundation\Testing\RefreshDatabase
- CameraSource
- TestCase
- RolePermission
- Index
- scripts
- AppServiceProvider.php
- composer.json
- require
- require-dev
- Index
- Illuminate\View\Component
- AuthApiTest
- CameraIngestTest
- config
- categories/index.blade.php
- products/index.blade.php
- users/index.blade.php
- AuthenticationTest
- VerifyEmailController.php
- VerifyMlSignature.php
- LoginForm
- KameraSimulasi
- PasswordResetTest
- psr-4
- extra
- PasswordConfirmationTest
- annotation/index.blade.php
- roles/index.blade.php
- app.blade.php
- ExampleTest
- profile.blade.php
- autoload-dev
- colab_prepare.py
- verify-email.blade.php
- make_colab_notebook.py
- resetForm
- guest.blade.php
- layout/navigation.blade.php
- training/index.blade.php
- welcome.blade.php

## God Nodes (most connected - your core abstractions)
1. `User` - 55 edges
2. `Detection` - 37 edges
3. `TestCase` - 31 edges
4. `Product` - 28 edges
5. `TrainingRun` - 21 edges
6. `Index` - 16 edges
7. `Setting` - 16 edges
8. `Controller` - 15 edges
9. `Index` - 15 edges
10. `Index` - 14 edges

## Surprising Connections (you probably didn't know these)
- `VerifyEmailController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/Auth/VerifyEmailController.php → app/Http/Controllers/Controller.php
- `ArmApiTest` --inherits--> `TestCase`  [EXTRACTED]
  tests/Feature/Api/ArmApiTest.php → tests/TestCase.php
- `ArmMqttServiceTest` --inherits--> `TestCase`  [EXTRACTED]
  tests/Feature/Api/ArmMqttServiceTest.php → tests/TestCase.php
- `AuthApiTest` --inherits--> `TestCase`  [EXTRACTED]
  tests/Feature/Api/AuthApiTest.php → tests/TestCase.php
- `DetectionApiTest` --inherits--> `TestCase`  [EXTRACTED]
  tests/Feature/Api/DetectionApiTest.php → tests/TestCase.php

## Import Cycles
- None detected.

## Communities (117 total, 19 thin omitted)

### Community 0 - "Product"
Cohesion: 0.06
Nodes (12): Logout, Index, Index, Index, PublicProduct, Category, Product, Illuminate\Database\Eloquent\Relations\HasMany (+4 more)

### Community 1 - "Illuminate\Database\Eloquent\Model"
Cohesion: 0.08
Nodes (16): ArmController, AuthController, CameraController, DetectionController, MlCallbackController, StatusController, Controller, Index (+8 more)

### Community 2 - "main.py"
Cohesion: 0.05
Nodes (46): BackgroundTasks, BaseModel, BaseSettings, FastAPI, complete(), fail(), _post(), post_detection() (+38 more)

### Community 3 - "Detection"
Cohesion: 0.08
Nodes (13): Index, Dashboard, Annotation, Detection, AnnotationFactory, static, static, TrainingRunFactory (+5 more)

### Community 4 - "ArmMqttService"
Cohesion: 0.09
Nodes (10): ActivateModelCommand, MqttListen, RegenerateQrCommand, self, TargetZonePreset, ArmMqttService, Illuminate\Console\Command, PhpMqtt\Client\ConnectionSettings (+2 more)

### Community 5 - "TrainingRun"
Cohesion: 0.14
Nodes (8): StartTrainingRun, Index, TrainingRun, MlClient, Illuminate\Contracts\Queue\ShouldQueue, Illuminate\Foundation\Queue\Queueable, Illuminate\Http\Client\PendingRequest, Illuminate\Support\Collection

### Community 6 - "User"
Cohesion: 0.12
Nodes (5): User, Illuminate\Foundation\Auth\User, DetectionApiTest, StatusApiTest, ProfileTest

### Community 7 - "devDependencies"
Cohesion: 0.10
Nodes (20): autoprefixer, axios, laravel-vite-plugin, devDependencies, autoprefixer, axios, laravel-vite-plugin, postcss (+12 more)

### Community 8 - "Illuminate\Database\Eloquent\Factories\Factory"
Cohesion: 0.14
Nodes (7): CategoryFactory, DetectionFactory, ProductFactory, SystemLogFactory, static, UserFactory, Illuminate\Database\Eloquent\Factories\Factory

### Community 9 - "Illuminate\Foundation\Testing\RefreshDatabase"
Cohesion: 0.17
Nodes (5): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Notifications\Notifiable, Laravel\Sanctum\HasApiTokens, ArmApiTest, EmailVerificationTest

### Community 10 - "CameraSource"
Cohesion: 0.15
Nodes (6): CameraSource, A moving box on a conveyor-like backdrop (no hardware/sample)., Thread-safe holder of the most recent frame from the active source., Try the real RTSP stream first; return (cap, mode) or (None, ...)., Open the fallback source (webcam index or looped video file)., Continuously fill the frame buffer, reconnecting as needed.

### Community 11 - "TestCase"
Cohesion: 0.16
Nodes (5): Illuminate\Foundation\Testing\TestCase, PasswordUpdateTest, RegistrationTest, ExampleTest, TestCase

### Community 14 - "scripts"
Cohesion: 0.17
Nodes (12): scripts, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump, @php artisan key:generate --ansi, @php artisan migrate --ansi (+4 more)

### Community 15 - "AppServiceProvider.php"
Cohesion: 0.27
Nodes (3): AppServiceProvider, VoltServiceProvider, Illuminate\Support\ServiceProvider

### Community 16 - "composer.json"
Cohesion: 0.20
Nodes (9): description, keywords, license, minimum-stability, name, prefer-stable, type, framework (+1 more)

### Community 17 - "require"
Cohesion: 0.22
Nodes (9): require, laravel/framework, laravel/sanctum, laravel/tinker, livewire/livewire, livewire/volt, php, php-mqtt/client (+1 more)

### Community 18 - "require-dev"
Cohesion: 0.22
Nodes (9): require-dev, fakerphp/faker, laravel/breeze, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit (+1 more)

### Community 20 - "Illuminate\View\Component"
Cohesion: 0.43
Nodes (4): AppLayout, GuestLayout, Illuminate\View\Component, Illuminate\View\View

### Community 23 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 24 - "categories/index.blade.php"
Cohesion: 0.29
Nodes (6): confirmDelete({{ $category->id }}), edit({{ $category->id }}), closeModal, create, delete({{ $confirmingDeleteId }}), $set(

### Community 25 - "products/index.blade.php"
Cohesion: 0.29
Nodes (6): confirmDelete({{ $product->id }}), edit({{ $product->id }}), closeModal, create, delete({{ $confirmingDeleteId }}), $set(

### Community 26 - "users/index.blade.php"
Cohesion: 0.29
Nodes (6): confirmDelete({{ $user->id }}), edit({{ $user->id }}), closeModal, create, delete({{ $confirmingDeleteId }}), $set(

### Community 28 - "VerifyEmailController.php"
Cohesion: 0.47
Nodes (3): VerifyEmailController, Illuminate\Foundation\Auth\EmailVerificationRequest, Illuminate\Http\RedirectResponse

### Community 29 - "VerifyMlSignature.php"
Cohesion: 0.47
Nodes (3): VerifyMlSignature, Closure, Symfony\Component\HttpFoundation\Response

### Community 33 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 34 - "extra"
Cohesion: 0.40
Nodes (5): dev-master, extra, branch-alias, laravel, dont-discover

### Community 36 - "annotation/index.blade.php"
Cohesion: 0.50
Nodes (3): approve({{ $item->id }}), relabel({{ $item->id }}, , $set(

### Community 37 - "roles/index.blade.php"
Cohesion: 0.50
Nodes (3): cancel, edit(, save

### Community 38 - "app.blade.php"
Cohesion: 0.50
Nodes (3): layouts.partials.sidebar, layouts.partials.topbar, layouts.partials.theme

### Community 40 - "profile.blade.php"
Cohesion: 0.50
Nodes (3): profile.delete-user-form, profile.update-password-form, profile.update-profile-information-form

### Community 41 - "autoload-dev"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

## Knowledge Gaps
- **90 isolated node(s):** `name`, `type`, `description`, `laravel`, `framework` (+85 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **19 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `User` to `Product`, `Illuminate\Database\Eloquent\Model`, `PasswordResetTest`, `Detection`, `PasswordConfirmationTest`, `Illuminate\Foundation\Testing\RefreshDatabase`, `TestCase`, `RolePermission`, `Index`, `AuthApiTest`, `AuthenticationTest`?**
  _High betweenness centrality (0.069) - this node is a cross-community bridge._
- **Why does `Detection` connect `Detection` to `Product`, `Illuminate\Database\Eloquent\Model`, `ArmMqttService`, `TrainingRun`, `User`, `Illuminate\Database\Eloquent\Factories\Factory`, `Illuminate\Foundation\Testing\RefreshDatabase`, `CameraIngestTest`?**
  _High betweenness centrality (0.062) - this node is a cross-community bridge._
- **Why does `Product` connect `Product` to `Illuminate\Database\Eloquent\Factories\Factory`, `Illuminate\Database\Eloquent\Model`, `Detection`, `ArmMqttService`?**
  _High betweenness centrality (0.035) - this node is a cross-community bridge._
- **Are the 34 inferred relationships involving `User` (e.g. with `.render()` and `.run()`) actually correct?**
  _`User` has 34 INFERRED edges - model-reasoned connections that need verification._
- **What connects `name`, `type`, `description` to the rest of the system?**
  _90 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Product` be split into smaller, more focused modules?**
  _Cohesion score 0.06019871420222092 - nodes in this community are weakly interconnected._
- **Should `Illuminate\Database\Eloquent\Model` be split into smaller, more focused modules?**
  _Cohesion score 0.07676767676767676 - nodes in this community are weakly interconnected._