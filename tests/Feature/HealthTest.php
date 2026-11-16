<?php

it('responds on the health route', function (): void {
    $this->get('/up')->assertOk();
});
