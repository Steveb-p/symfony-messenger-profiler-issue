The issue occurs because of a conflict between Symfony's debug mode and the absence of the `WebProfilerBundle`. 

<img width="1059" height="684" alt="image" src="https://github.com/user-attachments/assets/26747b07-ea6a-409d-85c0-98b6c1470d46" />

To trigger the issue (assuming docker / database is set up):
```
php bin/console app:messenger_test -vv
php bin/console messenger:consume -vv
```

Why it happens: 
  
1. In the dev environment, Symfony's `kernel.debug` is true. This causes `FrameworkBundle` to 
decorate the standard `event_dispatcher` with `TraceableEventDispatcher`.
     
2. When `WebProfilerBundle` is disabled, the `framework.profiler.enabled` setting defaults to `false`.  
  
3. Messenger Service Resets: Symfony Messenger workers are designed to reset services (via `ServicesResetter`) after each message.
This calls the `reset()` method on the `TraceableEventDispatcher`. 
     
4. The Crash: The `TraceableEventDispatcher::reset()` method clears its internal `dispatchDepth` array. The Messenger Worker 
dispatches a `WorkerRunningEvent` immediately after the reset. **In some configurations where the profiler is missing but debug 
is on**, the `TraceableEventDispatcher` attempts to decrement the `dispatchDepth` for the 
`WorkerRunningEvent` in its `finally` block (`postProcess()`), leading 
to the `Warning: Undefined array key`. 

I reproduced the crash by explicitly setting `framework.profiler.enabled: false` while keeping the bundle installed and debug mode 
on. The error disappeared immediately when running with the `--no-debug` flag.

The reason the `WebProfilerBundle` prevents this crash is actually an intentional safety mechanism in Symfony's core 
(`FrameworkBundle`), **but it only "activates" when the profiler is enabled**.

The Specific Code: `TraceableEventDispatcher::__construct` 
When you have the `WebProfilerBundle` installed and enabled, Symfony passes a special "disabled state checker" closure to the 
`TraceableEventDispatcher` constructor. 

In vendor/symfony/framework-bundle/Resources/config/debug.php: 
```php
->set('debug.event_dispatcher', TraceableEventDispatcher::class) 
     ->decorate('event_dispatcher') 
     ->args([ 
         // ... 
         service('profiler.is_disabled_state_checker')->nullOnInvalid(), 
     ]) 
```

How it prevents the crash: 
At the very beginning of the dispatch() method in `TraceableEventDispatcher.php`, the dispatcher checks this closure: 

```php
public function dispatch(object $event, ?string $eventName = null): object 
{ 
    if ($this->disabled?->__invoke()) { 
        // If the profiler is disabled (e.g., during a CLI command), 
        // it bypasses all tracing logic and calls the original dispatcher directly. 
        return $this->dispatcher->dispatch($event, $eventName); 
    } 
    // ... tracing logic that crashes ... 
} 
```

When `WebProfilerBundle` is enabled, the `profiler.is_disabled_state_checker` is registered. In a CLI environment (like 
`messenger:consume`), the profiler is automatically considered "disabled" for that process.  

1. With Profiler Bundle: The disabled check returns true, the dispatcher bypasses itself, and the tracing state (which would 
have been cleared by the reset) is never touched. Result: No crash.

2. Without Profiler Bundle: The `profiler.is_disabled_state_checker` service is removed from the container (logic in 
`FrameworkExtension::registerProfilerConfiguration`). The `TraceableEventDispatcher` receives `null` for the `$disabled` argument. It 
then attempts to run the full tracing logic, which hits the inconsistent state after the reset. Result: Crash.
