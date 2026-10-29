#ifndef XHPROF_TRACE_H
#define XHPROF_TRACE_H

static zend_always_inline void hp_mode_common_beginfn(hp_entry_t **entries, hp_entry_t *current)
{
    hp_entry_t *p;

    /* This symbol's recursive level */
    int recurse_level = 0;

    if (XHPROF_G(func_hash_counters[current->hash_code]) > 0) {
        /* Find this symbols recurse level */
        for (p = (*entries); p; p = p->prev_hprof) {
            if (zend_string_equals(current->name_hprof, p->name_hprof)) {
                recurse_level = (p->rlvl_hprof) + 1;
                break;
            }
        }
    }

    XHPROF_G(func_hash_counters[current->hash_code])++;

    /* Init current function's recurse level */
    current->rlvl_hprof = recurse_level;
}

static zend_always_inline int hp_ignored_functions_filter_collision(hp_ignored_functions *functions, zend_ulong hash)
{
    zend_ulong idx = hash % XHPROF_MAX_IGNORED_FUNCTIONS;
    return functions->filter[idx];
}

static zend_always_inline int hp_ignore_entry_work(zend_ulong hash_code, zend_string *curr_func)
{
    if (XHPROF_G(ignored_functions) == NULL) {
        return 0;
    }

    hp_ignored_functions *functions = XHPROF_G(ignored_functions);

    if (hp_ignored_functions_filter_collision(functions, hash_code)) {
        int i = 0;
        for (; functions->names[i] != NULL; i++) {
            zend_string *name = functions->names[i];
            if (zend_string_equals(curr_func, name)) {
                return 1;
            }
        }
    }

    return 0;
}

static zend_always_inline zend_string *hp_get_function_name(zend_execute_data *execute_data)
{
    zend_function *curr_func;
    zend_string *real_function_name;

    if (!execute_data) {
        return NULL;
    }

    curr_func = execute_data->func;

    if (!curr_func->common.function_name) {
        return NULL;
    }

    if (curr_func->common.scope != NULL) {
        real_function_name = strpprintf(0, "%s::%s", curr_func->common.scope->name->val, ZSTR_VAL(curr_func->common.function_name));
    } else {
        real_function_name = zend_string_copy(curr_func->common.function_name);
    }

    return real_function_name;
}

static zend_always_inline zend_string *hp_get_trace_callback(zend_string *function_name, zend_execute_data *data)
{
    zend_string *trace_name;
    hp_trace_callback *callback;

    if (XHPROF_G(trace_callbacks)) {
        callback = (hp_trace_callback*)zend_hash_find_ptr(XHPROF_G(trace_callbacks), function_name);
        if (callback) {
            trace_name = (*callback)(function_name, data);
        } else {
            return function_name;
        }
    } else {
        return function_name;
    }

    zend_string_release(function_name);

    return trace_name;
}

static zend_always_inline hp_entry_t *hp_fast_alloc_hprof_entry()
{
    hp_entry_t *p;

    p = XHPROF_G(entry_free_list);

    if (p) {
        XHPROF_G(entry_free_list) = p->prev_hprof;
        return p;
    } else {
        return (hp_entry_t *)malloc(sizeof(hp_entry_t));
    }
}

static zend_always_inline void hp_fast_free_hprof_entry(hp_entry_t *p)
{
    if (p->name_hprof != NULL) {
        zend_string_release(p->name_hprof);
    }

    /* we use/overload the prev_hprof field in the structure to link entries in
     * the free list.
     * */
    p->prev_hprof = XHPROF_G(entry_free_list);
    XHPROF_G(entry_free_list) = p;
}

/* True when a call must not be recorded because of max_depth or max_calls. */
static zend_always_inline int hp_over_limit(hp_entry_t *top)
{
    if (XHPROF_G(max_depth) > 0 && top->depth >= XHPROF_G(max_depth)) {
        return 1;
    }

    if (XHPROF_G(max_calls) > 0) {
        if (XHPROF_G(call_count) >= XHPROF_G(max_calls)) {
            return 1;
        }
        XHPROF_G(call_count)++;
    }

    return 0;
}

/* Called instead of recording a call that is over a limit. Nothing deeper
 * than a limited call is ever recorded (depth only grows, and the call count
 * never goes down), so no recorded entry sits above one of these. */
static zend_always_inline int hp_skip_limited_call(hp_entry_t **entries, zend_string *function_name)
{
    zend_string_release(function_name);

#if PHP_VERSION_ID >= 80000
    /* Observers pair begin and end for every call, so keep the stack balanced. */
    hp_entry_t *cur_entry = hp_fast_alloc_hprof_entry();
    (cur_entry)->name_hprof = zend_string_copy((*(entries))->name_hprof);
    (cur_entry)->prev_hprof = (*(entries));
    (cur_entry)->is_trace = 0;
    (cur_entry)->hash_code = XHPROF_LIMIT_COUNTER;
    (cur_entry)->rlvl_hprof = 0;
    (cur_entry)->depth = (*(entries))->depth + 1;
    XHPROF_G(func_hash_counters[XHPROF_LIMIT_COUNTER])++;
    (*(entries)) = (cur_entry);
    return 1;
#else
    return 0;
#endif
}

static zend_always_inline int begin_profiling(zend_string *root_symbol, zend_execute_data *execute_data)
{
    zend_string *function_name;
    hp_entry_t **entries = &XHPROF_G(entries);

    if (root_symbol == NULL) {
        function_name = hp_get_function_name(execute_data);
    } else {
        function_name = zend_string_copy(root_symbol);
    }

    if (function_name == NULL) {
        return 0;
    }

    zend_ulong hash_code = ZSTR_HASH(function_name);
    int profile_curr = !hp_ignore_entry_work(hash_code, function_name);

    if (profile_curr && (*entries) != NULL && hp_over_limit(*entries)) {
        return hp_skip_limited_call(entries, function_name);
    }

    if (profile_curr) {
        if (execute_data != NULL) {
            function_name = hp_get_trace_callback(function_name, execute_data);
        }

        hp_entry_t *cur_entry = hp_fast_alloc_hprof_entry();
        (cur_entry)->hash_code = hash_code % XHPROF_FUNC_HASH_COUNTERS_SIZE;
        (cur_entry)->name_hprof = function_name;
        (cur_entry)->prev_hprof = (*(entries));
        (cur_entry)->rlvl_hprof = 0;
        (cur_entry)->depth = (*(entries)) ? (*(entries))->depth + 1 : 0;
#if PHP_VERSION_ID >= 80000
        (cur_entry)->is_trace = 1;
#endif
        /* Call the universal callback */
        hp_mode_common_beginfn((entries), (cur_entry));
        /* Call the mode's beginfn callback */
        XHPROF_G(mode_cb).begin_fn_cb((entries), (cur_entry));
        /* Update entries linked list */
        (*(entries)) = (cur_entry);
    } else {
#if PHP_VERSION_ID >= 80000
        hp_entry_t *cur_entry = hp_fast_alloc_hprof_entry();
        (cur_entry)->name_hprof = zend_string_copy((*(entries))->name_hprof);
        (cur_entry)->prev_hprof = (*(entries));
        (cur_entry)->is_trace = 0;
        (cur_entry)->hash_code = (*(entries))->hash_code;
        (cur_entry)->rlvl_hprof = 0;
        (cur_entry)->depth = (*(entries))->depth + 1;
        (*(entries)) = (cur_entry);
        profile_curr = 1;
#endif
        zend_string_release(function_name);
    }

    return profile_curr;
}

static zend_always_inline void end_profiling()
{
    hp_entry_t *cur_entry;
    hp_entry_t **entries = &XHPROF_G(entries);

    /* Call the mode's endfn callback. */
    /* NOTE(cjiang): we want to call this 'end_fn_cb' before */
    /* 'hp_mode_common_endfn' to avoid including the time in */
    /* 'hp_mode_common_endfn' in the profiling results.      */
    XHPROF_G(mode_cb).end_fn_cb(entries);
    cur_entry = (*(entries));
    /* Free top entry and update entries linked list */
    (*(entries)) = (*(entries))->prev_hprof;
    hp_fast_free_hprof_entry(cur_entry);
}
#if PHP_VERSION_ID >= 80100
/*
 * Fibers.
 *
 * Each fiber has its own call stack, but the profiler keeps a single stack of
 * entries. Without help, a fiber that suspends in the middle of a call leaves
 * its entries on top of the resumer's stack, so the resumer's next calls (and
 * its function returns) are attributed to the wrong functions.
 *
 * On every fiber switch the current stack is parked in fiber_stacks, keyed by
 * the fiber context it belongs to, and the stack of the context being
 * switched to is restored. A context seen for the first time (a new fiber, or
 * one that was already running when profiling was enabled) gets a fresh stack
 * holding a single base entry named after the function that started or
 * resumed it, so its calls show up as "Fiber::start==>function". Stacks never
 * share entries, so freeing one cannot invalidate another.
 */

/* Free a whole stack without recording anything, undoing its counter bumps. */
static void hp_discard_entry_stack(hp_entry_t *p)
{
    hp_entry_t *prev;

    while (p) {
        prev = p->prev_hprof;

        /* Traced entries and base entries (no prev) incremented the counter;
         * entries for ignored functions did not. */
        if ((p->is_trace || !p->prev_hprof) && XHPROF_G(func_hash_counters[p->hash_code]) > 0) {
            XHPROF_G(func_hash_counters[p->hash_code])--;
        }

        hp_fast_free_hprof_entry(p);
        p = prev;
    }
}

static hp_entry_t *hp_new_fiber_base_entry(zend_string *name, zend_ulong hash_code, int depth)
{
    hp_entry_t *base = hp_fast_alloc_hprof_entry();

    base->name_hprof = zend_string_copy(name);
    base->prev_hprof = NULL;
    base->rlvl_hprof = 0;
    base->depth = depth;
    base->hash_code = hash_code;
    /* Popping it must not record a call. */
    base->is_trace = 0;
    base->tsc_start = 0;
    base->cpu_start = 0;
    base->mu_start_hprof = 0;
    base->pmu_start_hprof = 0;

    XHPROF_G(func_hash_counters[hash_code])++;

    return base;
}

static void hp_free_fiber_stacks()
{
    hp_entry_t *stack;

    if (!XHPROF_G(fiber_stacks)) {
        return;
    }

    ZEND_HASH_FOREACH_PTR(XHPROF_G(fiber_stacks), stack) {
        hp_discard_entry_stack(stack);
    } ZEND_HASH_FOREACH_END();

    zend_hash_destroy(XHPROF_G(fiber_stacks));
    FREE_HASHTABLE(XHPROF_G(fiber_stacks));
    XHPROF_G(fiber_stacks) = NULL;
}

static void hp_fiber_switch(zend_fiber_context *from, zend_fiber_context *to)
{
    hp_entry_t *top, *next;
    zend_string *name;
    zend_ulong hash_code;

    if (!XHPROF_G(enabled)) {
        return;
    }

    top = XHPROF_G(entries);

    /* Name for a base entry, in case the target needs one. */
    if (top) {
        name = top->name_hprof;
        hash_code = top->hash_code;
    } else {
        name = XHPROF_G(root);
        hash_code = ZSTR_HASH(name) % XHPROF_FUNC_HASH_COUNTERS_SIZE;
    }

    if (!XHPROF_G(fiber_stacks)) {
        ALLOC_HASHTABLE(XHPROF_G(fiber_stacks));
        zend_hash_init(XHPROF_G(fiber_stacks), 8, NULL, NULL, 0);
    }

    next = zend_hash_index_find_ptr(XHPROF_G(fiber_stacks), (zend_ulong)(uintptr_t)to);

    if (next) {
        zend_hash_index_del(XHPROF_G(fiber_stacks), (zend_ulong)(uintptr_t)to);
    } else {
        next = hp_new_fiber_base_entry(name, hash_code, top ? top->depth : 0);
    }

    if (from->status == ZEND_FIBER_STATUS_DEAD) {
        /* The fiber finished: its stack is just the base entry. */
        hp_discard_entry_stack(top);
    } else if (top) {
        zend_hash_index_update_ptr(XHPROF_G(fiber_stacks), (zend_ulong)(uintptr_t)from, top);
    }

    XHPROF_G(entries) = next;
}
#endif

#endif