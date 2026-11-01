<template>
  <q-layout view="hHh lpR fFf">
    <q-page-container>
      <q-page class="auth-page flex flex-center q-pa-md">
        <div class="auth-box">
          <div class="text-center q-mb-lg">
            <q-icon name="support_agent" size="48px" color="primary" aria-hidden="true" />
            <h1 class="text-h5 q-my-sm">Psicol · Gestión de incidentes</h1>
          </div>
          <router-view />
        </div>
      </q-page>
    </q-page-container>
  </q-layout>
</template>

<style scoped>
.auth-page {
  background: linear-gradient(160deg, #e8eef5 0%, #f4f6f9 60%);
}

.auth-box {
  width: 100%;
  max-width: 400px;
}
</style>
